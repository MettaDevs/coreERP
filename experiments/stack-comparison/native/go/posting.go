package main

import (
	"crypto/sha256"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/shopspring/decimal"
)

func (q *request) settlementMode(company, date string) (string, error) {
	r, e := q.row("select mode from finance_settlement_modes where legal_entity_id=$1 and effective_from<=$2 order by effective_from desc limit 1", company, date)
	if errors.Is(e, pgx.ErrNoRows) {
		return "direct_payable", nil
	}
	if e != nil {
		return "", e
	}
	return str(r["mode"]), nil
}
func (q *request) businessUnits(ids []string, date string) (map[string]object, error) {
	versions, e := q.rows("select v.id,v.hierarchy_id from organization_hierarchy_versions v join organization_hierarchies h on h.id=v.hierarchy_id join organization_hierarchy_purposes link on link.hierarchy_id=h.id join hierarchy_purposes purpose on purpose.id=link.purpose_id where h.tenant_id=$1 and h.status='active' and purpose.code='management' and v.status='published' and v.effective_from<($2::date+interval '1 day') order by v.effective_from desc,v.version_number desc", q.authority.tenant, date)
	if e != nil {
		return nil, e
	}
	selected := []string{}
	seen := map[string]bool{}
	for _, v := range versions {
		if !seen[str(v["hierarchy_id"])] {
			selected = append(selected, str(v["id"]))
			seen[str(v["hierarchy_id"])] = true
		}
	}
	result := map[string]object{}
	if len(selected) == 0 {
		return result, nil
	}
	ancestors, e := q.rows("select c.version_id,c.descendant_organization_id,c.ancestor_organization_id,o.name,u.number from organization_hierarchy_closures c join operating_units u on u.organization_id=c.ancestor_organization_id join organizations o on o.id=c.ancestor_organization_id where c.version_id=any($1::text[]) and c.descendant_organization_id=any($2::text[]) and u.type='business_unit' and o.tenant_id=$3 order by c.distance", selected, ids, q.authority.tenant)
	if e != nil {
		return nil, e
	}
	nearest := map[string]bool{}
	candidates := map[string]map[string]object{}
	for _, a := range ancestors {
		id := str(a["descendant_organization_id"])
		key := id + "|" + str(a["version_id"])
		if nearest[key] {
			continue
		}
		nearest[key] = true
		if candidates[id] == nil {
			candidates[id] = map[string]object{}
		}
		candidates[id][str(a["ancestor_organization_id"])] = object{"id": a["ancestor_organization_id"], "name": a["name"], "number": a["number"]}
	}
	for id, m := range candidates {
		if len(m) == 1 {
			for _, v := range m {
				result[id] = v
			}
		}
	}
	return result, nil
}
func (q *request) buildPosting(h object, lines, assets []object, persist bool) (object, error) {
	tenant := q.authority.tenant
	company, date, currency := str(h["legal_entity_id"]), str(h["tanggal"]), str(h["currency_code"])
	mode, e := q.settlementMode(company, date)
	if e != nil {
		return nil, e
	}
	amount, _, e := q.precision(currency)
	if e != nil {
		return nil, e
	}
	groupIDs := []string{}
	for _, d := range lines {
		groupIDs = append(groupIDs, str(d["group_aset_id"]))
	}
	groupRows, e := q.rows("select id,kode,nama,lokasi_aset_id from aset_m_group_aset where tenant_id=$1 and id=any($2::text[])", tenant, groupIDs)
	if e != nil {
		return nil, e
	}
	groups := map[string]object{}
	for _, g := range groupRows {
		groups[str(g["id"])] = g
	}
	type aggregate struct {
		group, unit string
		value, tax  decimal.Decimal
	}
	aggregates := []aggregate{}
	indexes := map[string]int{}
	for _, d := range lines {
		group := str(d["group_aset_id"])
		location := str(h["lokasi_aset_id"])
		if location == "" {
			location = str(groups[group]["lokasi_aset_id"])
		}
		unit, e := q.inheritedLocation(location, "org_unit_id")
		if e != nil {
			return nil, e
		}
		if unit == "" {
			unit = str(h["responsible_org_unit_id"])
		}
		key := group + "|" + unit
		i, exists := indexes[key]
		if !exists {
			i = len(aggregates)
			indexes[key] = i
			aggregates = append(aggregates, aggregate{group: group, unit: unit, value: decimal.Zero, tax: decimal.Zero})
		}
		a := &aggregates[i]
		n := integer(d["jumlah"])
		a.value = a.value.Add(money(extended(d["nilai_per_unit"], n, amount)))
		a.tax = a.tax.Add(money(extended(d["ppn_per_unit"], n, amount)))
		if _, e = q.postedBook(group); e != nil {
			return nil, e
		}
	}
	vendor, e := q.vendor(str(h["vendor_id"]))
	if e != nil {
		return nil, e
	}
	mappings := map[string]object{}
	journal := []object{}
	labels := map[string]string{"acquisition_account_id": "harga perolehan", "input_vat_account_id": "PPN Masukan", "payable_account_id": "lawan hutang", "clearing_account_id": "perantara"}
	appendLine := func(a aggregate, column string, debit, credit decimal.Decimal, description string) error {
		mapping, ok := mappings[a.group]
		if !ok {
			mapping, e = q.row("select * from aset_m_posting_group where tenant_id=$1 and group_aset_id=$2 and deleted_at is null and effective_from<=$3 order by effective_from desc limit 1", tenant, a.group, date)
			if e != nil && !errors.Is(e, pgx.ErrNoRows) {
				return e
			}
			mappings[a.group] = mapping
		}
		debitValue, creditValue := debit.StringFixed(amount), credit.StringFixed(amount)
		if debit.IsZero() {
			debitValue = "0"
		}
		if credit.IsZero() {
			creditValue = "0"
		}
		journal = append(journal, object{"account_id": mapping[column], "debit": debitValue, "credit": creditValue, "description": truncate(description, 255), "org_unit_id": a.unit, "mapping": object{"label": "Group " + str(groups[a.group]["kode"]) + " · " + labels[column], "fix_url": "/management-aset/fixed-aset-posting-profiles", "reference": "posting-group:" + a.group + ":" + column}})
		return nil
	}
	for _, a := range aggregates {
		if !a.value.IsZero() {
			if e = appendLine(a, "acquisition_account_id", a.value, decimal.Zero, str(h["kode"])+" · "+str(groups[a.group]["nama"])); e != nil {
				return nil, e
			}
		}
	}
	for _, a := range aggregates {
		if !a.tax.IsZero() {
			if e = appendLine(a, "input_vat_account_id", a.tax, decimal.Zero, "PPN Masukan · "+str(h["kode"])+" · "+str(groups[a.group]["nama"])); e != nil {
				return nil, e
			}
		}
	}
	offset := "payable_account_id"
	if mode == "clearing" {
		offset = "clearing_account_id"
	}
	for _, a := range aggregates {
		total := a.value.Add(a.tax)
		if !total.IsZero() {
			description := str(h["kode"]) + " · " + str(groups[a.group]["nama"])
			if vendor != nil {
				description = str(vendor["name"]) + " · " + str(h["kode"])
			}
			if e = appendLine(a, offset, decimal.Zero, total, description); e != nil {
				return nil, e
			}
		}
	}
	if len(journal) == 0 {
		return nil, nil
	}
	assetDetails := []object{}
	books := map[string]object{}
	for _, a := range assets {
		group := str(a["group_aset_id"])
		b, ok := books[group]
		if !ok {
			b, e = q.postedBook(group)
			if e != nil {
				return nil, e
			}
			books[group] = b
		}
		assetDetails = append(assetDetails, object{"asset_code": a["asset_code"], "asset_group": groups[group]["kode"], "book": b["kode"], "acquisition_value": a["acquisition_value"], "tax_amount": a["tax_amount"]})
	}
	description := strings.TrimSpace(str(h["keterangan"]))
	if description == "" {
		description = "Penerimaan aset " + str(h["kode"])
	}
	documentDate := h["vendor_invoice_date"]
	if documentDate == nil {
		documentDate = date
	}
	now := time.Now().UTC()
	occurred := now.Format("2006-01-02T15:04:05+00:00")
	input := object{"tenant_id": tenant, "posting_id": "AST-ACQ-" + str(h["id"]), "posting_type": "asset.acquisition", "legal_entity_id": company, "currency_code": currency, "posting_date": date, "document_date": documentDate, "occurred_at": occurred, "settlement_mode": mode, "requires_vendor": mode == "direct_payable" && (persist || h["vendor_id"] != nil), "vendor_id": h["vendor_id"], "vendor_invoice_reference": h["vendor_invoice_reference"], "source_document": object{"module": "management-aset", "type": "penerimaan-aset", "number": h["kode"], "description": truncate(description, 255), "id": h["id"], "url": "/management-aset/inventarisasi-aset/penerimaan/" + str(h["id"])}, "lines": journal, "details": object{"receipt": object{"id": h["id"], "number": h["kode"], "purchase_order_reference": h["purchase_order_reference"], "attachment_record_type": receiptTable}, "assets": assetDetails}}
	return q.publishPosting(input, persist)
}
func truncate(s string, n int) string {
	r := []rune(s)
	if len(r) > n {
		return string(r[:n])
	}
	return s
}
func (q *request) publishPosting(input object, persist bool) (object, error) {
	tenant := q.authority.tenant
	company := str(input["legal_entity_id"])
	postingID := str(input["posting_id"])
	date := str(input["posting_date"])
	existing, e := q.row("select * from finance_postings where tenant_id=$1 and posting_id=$2", tenant, postingID)
	if e != nil && !errors.Is(e, pgx.ErrNoRows) {
		return nil, e
	}
	if _, e = q.row("select * from organizations where tenant_id=$1 and id=$2 and classification='legal_entity'", tenant, company); e != nil {
		return nil, e
	}
	entity, e := q.row("select company_code from legal_entities where organization_id=$1", company)
	if e != nil {
		return nil, e
	}
	amount, _, e := q.precision(str(input["currency_code"]))
	if e != nil {
		return nil, e
	}
	if existing != nil {
		amount = int32(integer(existing["currency_decimals"]))
	}
	v, e := q.vendor(str(input["vendor_id"]))
	if e != nil {
		return nil, e
	}
	var vendor any
	if v != nil {
		if str(v["legal_entity_id"]) != company {
			return nil, errors.New("posting vendor belongs to another legal entity")
		}
		vendor = take(v, "id", "number", "name")
	} else if boolean(input["requires_vendor"]) {
		return nil, errors.New("required posting vendor missing")
	}
	journal := []object{}
	for _, original := range objects(input["lines"]) {
		line := object{}
		for key, value := range original {
			line[key] = value
		}
		journal = append(journal, line)
	}
	if len(journal) < 2 || len(journal) > 5000 {
		return nil, errors.New("posting journal line count invalid")
	}
	debit, credit := decimal.Zero, decimal.Zero
	accountIDs, unitIDs := []string{}, []string{}
	hashLines := []any{}
	for i, l := range journal {
		d, k := money(l["debit"]), money(l["credit"])
		if d.IsNegative() || k.IsNegative() || d.IsZero() == k.IsZero() || !d.Equal(d.Round(amount)) || !k.Equal(k.Round(amount)) {
			return nil, errors.New("invalid posting money sides or precision")
		}
		l["line_no"] = i + 1
		l["debit"] = d.StringFixed(amount)
		l["credit"] = k.StringFixed(amount)
		debit = debit.Add(d)
		credit = credit.Add(k)
		if l["account_id"] != nil {
			accountIDs = append(accountIDs, str(l["account_id"]))
		}
		if l["org_unit_id"] != nil {
			unitIDs = append(unitIDs, str(l["org_unit_id"]))
		}
		hashLines = append(hashLines, []any{l["account_id"], l["debit"], l["credit"], l["org_unit_id"]})
	}
	if !debit.Equal(credit) {
		return nil, errors.New("posting journal unbalanced")
	}
	hash := fmt.Sprintf("%x", sha256.Sum256([]byte(jsonText([]any{input["posting_type"], company, input["currency_code"], date, input["document_date"], input["settlement_mode"], input["vendor_id"], nil, nil, hashLines}))))
	if existing != nil {
		if str(existing["input_hash"]) != hash {
			return nil, errors.New("posting replay input mismatch")
		}
		problems := existing["hold_reasons"]
		if problems == nil {
			problems = []object{}
		}
		return object{"posting_id": postingID, "status": existing["status"], "problems": problems, "payload": existing["payload"], "created": false}, nil
	}
	accountRows, e := q.rows("select * from finance_reference_accounts where tenant_id=$1 and id=any($2::text[])", tenant, accountIDs)
	if e != nil {
		return nil, e
	}
	accounts := map[string]object{}
	for _, a := range accountRows {
		accounts[str(a["id"])] = a
	}
	unitRows, e := q.rows("select o.id,o.name,o.classification,u.type,u.number from organizations o left join operating_units u on u.organization_id=o.id where o.tenant_id=$1 and o.id=any($2::text[])", tenant, unitIDs)
	if e != nil {
		return nil, e
	}
	units := map[string]object{}
	for _, u := range unitRows {
		units[str(u["id"])] = u
	}
	bus, e := q.businessUnits(unitIDs, date)
	if e != nil {
		return nil, e
	}
	problems := []object{}
	payloadLines, tableLines := []object{}, []object{}
	for _, l := range journal {
		a := accounts[str(l["account_id"])]
		mapping := jsonObject(l["mapping"])
		label := str(mapping["label"])
		fix := any(nil)
		if mapping["fix_url"] != nil {
			fix = object{"label": "Buka pemetaan akun", "url": mapping["fix_url"]}
		}
		issue := func(code, message string, obj, fix any) {
			problems = append(problems, object{"line_no": l["line_no"], "code": code, "message": message, "object": obj, "fix": fix})
		}
		switch {
		case l["account_id"] == nil:
			issue("ACCOUNT_NOT_MAPPED", label+" belum dipetakan ke akun.", nil, fix)
		case a == nil:
			issue("ACCOUNT_UNKNOWN", label+" menunjuk akun yang tidak ada di daftar akun.", object{"type": "account", "id": l["account_id"], "label": label}, fix)
		case !boolean(a["active"]):
			issue("ACCOUNT_INACTIVE", "Akun "+str(a["code"])+" "+str(a["name"])+" nonaktif.", object{"type": "account", "id": a["id"], "label": str(a["code"]) + " " + str(a["name"])}, fix)
		case a["legal_entity_id"] != nil && str(a["legal_entity_id"]) != company:
			issue("ACCOUNT_OTHER_LEGAL_ENTITY", "Akun "+str(a["code"])+" "+str(a["name"])+" khusus entitas legal lain.", object{"type": "account", "id": a["id"], "label": str(a["code"]) + " " + str(a["name"])}, fix)
		}
		u := units[str(l["org_unit_id"])]
		dimensions := []object{}
		var buCode, departmentCode any
		if l["org_unit_id"] == nil {
			issue("DIMENSION_SOURCE_MISSING", "Baris ini tidak menyebut unit organisasi, jadi dimensinya tidak dapat dibentuk.", nil, nil)
		} else if u == nil || str(u["classification"]) != "operating_unit" {
			issue("ORG_UNIT_UNKNOWN", "Unit organisasi baris ini tidak ditemukan.", object{"type": "organization", "id": l["org_unit_id"], "label": l["org_unit_id"]}, nil)
		} else {
			bu := bus[str(l["org_unit_id"])]
			if bu == nil {
				issue("BUSINESS_UNIT_UNRESOLVED", str(u["name"])+" tidak berada di bawah tepat satu business unit pada hierarki manajemen yang berlaku "+date+".", object{"type": "organization", "id": l["org_unit_id"], "label": u["name"]}, object{"label": "Buka hierarki organisasi", "url": "/settings/organization?section=hierarchies"})
			} else if bu["number"] == nil {
				issue("BUSINESS_UNIT_NUMBER_MISSING", str(bu["name"])+" belum punya nomor unit.", object{"type": "organization", "id": bu["id"], "label": bu["name"]}, object{"label": "Buka organisasi", "url": "/settings/organization?section=operating-units"})
			} else {
				buCode = bu["number"]
				dimensions = append(dimensions, object{"code": "BUSINESS_UNIT", "display_name": "Business unit", "value_code": bu["number"], "value_display_name": bu["name"], "value_id": bu["id"]})
			}
			if str(a["type"]) == "profit_loss" {
				if str(u["type"]) != "department" {
					issue("DEPARTMENT_REQUIRED", "Akun laba rugi "+str(a["code"])+" membutuhkan department, tetapi "+str(u["name"])+" bukan department.", object{"type": "organization", "id": u["id"], "label": u["name"]}, nil)
				} else if u["number"] == nil {
					issue("DEPARTMENT_NUMBER_MISSING", str(u["name"])+" belum punya nomor unit.", object{"type": "organization", "id": u["id"], "label": u["name"]}, object{"label": "Buka organisasi", "url": "/settings/organization?section=operating-units"})
				} else {
					departmentCode = u["number"]
					dimensions = append(dimensions, object{"code": "DEPARTMENT", "display_name": "Department", "value_code": u["number"], "value_display_name": u["name"], "value_id": u["id"]})
				}
			}
		}
		var account any
		if a != nil {
			account = take(a, "external_id", "code", "name")
		}
		payloadLines = append(payloadLines, object{"line_no": l["line_no"], "account": account, "debit": l["debit"], "credit": l["credit"], "description": l["description"], "financial_dimensions": dimensions})
		tableLines = append(tableLines, object{"line_no": l["line_no"], "account_id": a["id"], "account_external_id": a["external_id"], "account_code": a["code"], "debit": l["debit"], "credit": l["credit"], "description": l["description"], "org_unit_id": l["org_unit_id"], "business_unit_code": buCode, "department_code": departmentCode})
	}
	settings, e := q.row("select * from finance_posting_settings where legal_entity_id=$1", company)
	if e != nil && !errors.Is(e, pgx.ErrNoRows) {
		return nil, e
	}
	status := "pending"
	var reason any
	if settings == nil || !boolean(settings["enabled"]) {
		status = "manual"
		reason = "feed_disabled"
	} else if settings["cutover_date"] != nil && date < str(settings["cutover_date"]) {
		status = "manual"
		reason = "before_cutover"
	} else if len(problems) > 0 {
		status = "held"
	}
	source := jsonObject(input["source_document"])
	total := debit.StringFixed(amount)
	payload := object{"contract_version": 1, "posting_id": postingID, "posting_type": input["posting_type"], "settlement_mode": input["settlement_mode"], "legal_entity": object{"id": company, "code": entity["company_code"]}, "currency": object{"code": input["currency_code"], "decimals": amount}, "posting_date": date, "document_date": input["document_date"], "occurred_at": input["occurred_at"], "published_at": time.Now().UTC().Format("2006-01-02T15:04:05+00:00"), "source_document": take(source, "module", "type", "number", "description"), "vendor": vendor, "vendor_invoice_reference": input["vendor_invoice_reference"], "journal_lines": payloadLines, "totals": object{"debit": total, "credit": total}, "reverses_posting_id": nil, "adjusts_posting_id": nil, "details": input["details"]}
	if persist {
		if _, ok := q.db.(pgx.Tx); !ok {
			return nil, errors.New("posting publication requires transaction")
		}
		e = q.transaction(func() error {
			id := lowerUID()
			var holds any
			if status == "held" {
				holds = jsonText(problems)
			}
			occurred, e := time.Parse(time.RFC3339, str(input["occurred_at"]))
			if e != nil {
				return e
			}
			published, e := time.Parse(time.RFC3339, str(payload["published_at"]))
			if e != nil {
				return e
			}
			if e = q.insert("finance_postings", object{"id": id, "tenant_id": tenant, "legal_entity_id": company, "posting_id": postingID, "posting_type": input["posting_type"], "contract_version": 1, "source_module": source["module"], "source_type": source["type"], "source_number": source["number"], "source_id": source["id"], "currency_code": input["currency_code"], "currency_decimals": amount, "posting_date": date, "document_date": input["document_date"], "occurred_at": occurred, "published_at": published, "settlement_mode": input["settlement_mode"], "status": status, "manual_reason": reason, "hold_reasons": holds, "vendor_id": input["vendor_id"], "reverses_posting_id": nil, "adjusts_posting_id": nil, "total_debit": total, "total_credit": total, "payload": jsonText(payload), "input": jsonText(input), "input_hash": hash}); e != nil {
				return e
			}
			for _, l := range tableLines {
				l["id"] = lowerUID()
				l["tenant_id"] = tenant
				l["finance_posting_id"] = id
				if e = q.insert("finance_posting_lines", l); e != nil {
					return e
				}
			}
			return q.insert("finance_posting_events", object{"id": lowerUID(), "tenant_id": tenant, "finance_posting_id": id, "event": "published", "from_status": nil, "to_status": status, "integration_client_id": nil, "user_id": nil, "data": jsonText(object{"problems": map[bool]int{true: len(problems), false: 0}[status == "held"]})})
		})
		if e != nil {
			return nil, e
		}
	}
	returnedProblems := problems
	if status != "held" {
		returnedProblems = []object{}
	}
	return object{"posting_id": postingID, "status": status, "problems": returnedProblems, "payload": payload, "created": persist}, nil
}
func (q *request) previewReceipt(id string) (response, error) {
	if e := q.require("penerimaan-aset.read"); e != nil {
		return response{}, e
	}
	h, e := q.receipt(id)
	if e != nil {
		return response{}, e
	}
	if str(h["status"]) != "draft" {
		return response{}, fail(422, "Penerimaan ini sudah diselesaikan.")
	}
	lines, e := q.details(id, false)
	if e != nil {
		return response{}, e
	}
	blockers, e := q.blockers(h, lines)
	if e != nil {
		return response{}, e
	}
	var posting object
	if len(lines) == 0 {
		blockers["details"] = "Tambahkan baris barang lebih dulu."
	} else if blockers["currency_code"] == nil {
		posting, e = q.buildPosting(h, lines, nil, false)
		if e != nil {
			return response{}, e
		}
	}
	payload := jsonObject(posting["payload"])
	preview := []object{}
	for _, l := range objects(payload["journal_lines"]) {
		account := jsonObject(l["account"])
		dimensions := []object{}
		for _, d := range objects(l["financial_dimensions"]) {
			dimensions = append(dimensions, take(d, "code", "display_name", "value_code", "value_display_name"))
		}
		preview = append(preview, object{"line_no": l["line_no"], "account_code": account["code"], "account_name": account["name"], "description": l["description"], "debit": l["debit"], "credit": l["credit"], "dimensions": dimensions})
	}
	blockerRows := []object{}
	for k, v := range blockers {
		blockerRows = append(blockerRows, object{"field": k, "message": v})
	}
	problems := posting["problems"]
	if problems == nil {
		problems = []object{}
	}
	return response{body: object{"data": object{"blockers": blockerRows, "status": posting["status"], "posting_date": payload["posting_date"], "settlement_mode": payload["settlement_mode"], "currency": payload["currency"], "lines": preview, "problems": problems}}}, nil
}
