package main

import (
	"errors"
	"fmt"
	"net/http"
	"sort"
	"time"

	"github.com/jackc/pgx/v5"
)

var assetFields = []string{"id", "kode", "nama", "legal_entity_id", "responsible_org_unit_id", "group_aset_id", "kelompok_harta_fiskal_id", "jenis_aset_id", "kondisi_aset_id", "pabrikan_aset_id", "model_aset_id", "induk_aset_id", "lokasi_aset_id", "financial_dimension_org_unit_id", "serial_number", "model_number", "acquired_on", "placed_in_service_on", "acquisition_value", "currency_code", "lifecycle_state", "is_budgeted", "keterangan", "version"}

func presentAsset(a object) object {
	r := take(a, assetFields...)
	for _, f := range []string{"acquired_on", "placed_in_service_on"} {
		if r[f] != nil {
			r[f] = str(r[f]) + "T00:00:00.000000Z"
		}
	}
	r["acquisition_value"] = money(r["acquisition_value"]).StringFixed(2)
	return r
}
func (q *request) assetRoute(parts []string) (response, error) {
	if e := q.require("aset.read"); e != nil {
		return response{}, e
	}
	if q.r.Method != "GET" {
		return response{}, fail(404, "Not Found")
	}
	if len(parts) == 0 {
		return q.assetList()
	}
	if len(parts) != 1 {
		return response{}, fail(404, "Not Found")
	}
	a, e := q.row("select * from aset_tr_aset where tenant_id=$1 and id=$2 and deleted_at is null", q.authority.tenant, parts[0])
	if e != nil {
		return response{}, e
	}
	if !q.authority.allows(str(a["legal_entity_id"]), str(a["responsible_org_unit_id"])) {
		return response{}, pgx.ErrNoRows
	}
	attributes, e := q.rows("select t.id as tipe_atribut_id,t.kode,t.nama,t.data_type,t.satuan,a.tipe_atribut_nilai_id,a.nilai_text,a.nilai_number,a.nilai_boolean,a.nilai_date from aset_tr_aset_atribut a join aset_m_tipe_atribut t on t.id=a.tipe_atribut_id and t.tenant_id=a.tenant_id where a.tenant_id=$1 and a.aset_id=$2 order by t.nama", q.authority.tenant, parts[0])
	if e != nil {
		return response{}, e
	}
	for i, a := range attributes {
		r := take(a, "tipe_atribut_id", "kode", "nama", "data_type", "satuan", "tipe_atribut_nilai_id")
		r["nilai"] = a["nilai_text"]
		switch str(a["data_type"]) {
		case "decimal", "integer":
			if a["nilai_number"] == nil {
				r["nilai"] = nil
			} else {
				v, _ := money(a["nilai_number"]).Float64()
				r["nilai"] = v
			}
		case "boolean":
			r["nilai"] = a["nilai_boolean"]
		case "date":
			r["nilai"] = a["nilai_date"]
		}
		attributes[i] = r
	}
	out := presentAsset(a)
	out["atribut"] = attributes
	return response{body: object{"data": out}, headers: http.Header{"ETag": []string{fmt.Sprintf("W/\"%d\"", integer(a["version"]))}}}, nil
}
func (q *request) attributeRows(assetType string, submitted []object) ([]object, error) {
	ids, e := q.rows("select tipe_atribut_id from aset_m_jenis_aset_atribut where tenant_id=$1 and jenis_aset_id=$2 and deleted_at is null order by tipe_atribut_id", q.authority.tenant, assetType)
	if e != nil {
		return nil, e
	}
	typeIDs := []string{}
	for _, id := range ids {
		typeIDs = append(typeIDs, str(id["tipe_atribut_id"]))
	}
	if len(typeIDs) > 0 {
		if _, e = q.rows("select id from aset_m_tipe_atribut where tenant_id=$1 and id=any($2::text[]) order by id for update", q.authority.tenant, typeIDs); e != nil {
			return nil, e
		}
	}
	definitions, e := q.rows("select t.*,link.wajib,link.urutan from aset_m_jenis_aset_atribut link join aset_m_tipe_atribut t on t.id=link.tipe_atribut_id and t.tenant_id=link.tenant_id where link.tenant_id=$1 and link.jenis_aset_id=$2 and link.deleted_at is null and t.deleted_at is null and t.aktif=true order by link.urutan,t.nama", q.authority.tenant, assetType)
	if e != nil {
		return nil, e
	}
	choices := []object{}
	if len(typeIDs) > 0 {
		choices, e = q.rows("select id,tipe_atribut_id,nilai from aset_m_tipe_atribut_nilai where tenant_id=$1 and tipe_atribut_id=any($2::text[]) and deleted_at is null order by urutan,nilai", q.authority.tenant, typeIDs)
		if e != nil {
			return nil, e
		}
	}
	values := map[string]any{}
	known := map[string]bool{}
	for _, v := range submitted {
		values[str(v["tipe_atribut_id"])] = v["nilai"]
	}
	result := []object{}
	for _, d := range definitions {
		id := str(d["id"])
		known[id] = true
		raw := values[id]
		if raw == nil || raw == "" {
			if boolean(d["wajib"]) {
				return nil, invalid("atribut."+id, str(d["nama"])+" wajib diisi.")
			}
			continue
		}
		r := object{"id": uid(), "tenant_id": q.authority.tenant, "tipe_atribut_id": id, "nilai_text": nil, "nilai_number": nil, "nilai_boolean": nil, "nilai_date": nil, "tipe_atribut_nilai_id": nil}
		switch str(d["data_type"]) {
		case "decimal", "integer":
			n, e := decimalValue(raw)
			if e != nil {
				return nil, invalid("atribut."+id, str(d["nama"])+" harus berupa angka.")
			}
			if str(d["data_type"]) == "integer" && !n.Equal(n.Truncate(0)) {
				return nil, invalid("atribut."+id, str(d["nama"])+" harus berupa bilangan bulat.")
			}
			if (d["min_value"] != nil && n.LessThan(money(d["min_value"]))) || (d["max_value"] != nil && n.GreaterThan(money(d["max_value"]))) {
				return nil, invalid("atribut."+id, str(d["nama"])+" di luar batas nilai.")
			}
			r["nilai_number"] = n.String()
		case "boolean":
			r["nilai_boolean"] = raw == true || raw == "true" || raw == "1" || raw == "on" || raw == "yes"
		case "date":
			if _, e := time.Parse("2006-01-02", str(raw)); e != nil {
				return nil, invalid("atribut."+id, str(d["nama"])+" harus berupa tanggal (YYYY-MM-DD).")
			}
			r["nilai_date"] = str(raw)
		case "string":
			hasChoices := false
			found := false
			for _, c := range choices {
				if str(c["tipe_atribut_id"]) == id {
					hasChoices = true
					if str(c["nilai"]) == str(raw) {
						r["tipe_atribut_nilai_id"] = c["id"]
						found = true
					}
				}
			}
			if hasChoices && !found {
				return nil, invalid("atribut."+id, str(d["nama"])+" harus dipilih dari daftar yang tersedia.")
			}
			r["nilai_text"] = str(raw)
		default:
			return nil, invalid("atribut."+id, str(d["nama"])+" memakai tipe data yang tidak dikenal.")
		}
		result = append(result, r)
	}
	for id := range values {
		if !known[id] {
			return nil, invalid("atribut."+id, "Atribut ini tidak terdaftar pada jenis aset yang dipilih.")
		}
	}
	return result, nil
}
func (q *request) postedBook(group string) (object, error) {
	b, e := q.row("select b.id,b.kode from aset_m_group_buku_penyusutan m join aset_m_buku_penyusutan b on b.id=m.buku_id and b.tenant_id=m.tenant_id where m.tenant_id=$1 and m.group_aset_id=$2 and m.deleted_at is null and b.deleted_at is null and b.aktif=true and b.posting_layer<>'none' order by case b.posting_layer when 'current' then 0 else 1 end,b.kode limit 1", q.authority.tenant, group)
	if errors.Is(e, pgx.ErrNoRows) {
		return nil, nil
	}
	return b, e
}
func (q *request) blockers(h object, lines []object) (object, error) {
	problems := object{}
	amount, _, e := q.precision(str(h["currency_code"]))
	if e != nil {
		return nil, e
	}
	if amount > 2 {
		problems["currency_code"] = "Presisi nilai mata uang lebih halus dari yang dapat dicatat register aset."
	}
	if str(h["cara_perolehan"]) != "pembelian" {
		return nil, unsupported("Profil ini hanya mencakup pembelian.")
	}
	mode, e := q.settlementMode(str(h["legal_entity_id"]), str(h["tanggal"]))
	if e != nil {
		return nil, e
	}
	if h["vendor_id"] == nil && mode == "direct_payable" {
		problems["vendor_id"] = "Pilih vendornya. Entitas legal ini mencatat pembelian aset langsung sebagai hutang ke vendor, jadi vendor wajib diisi."
	}
	ids := []string{}
	for _, d := range lines {
		ids = append(ids, str(d["group_aset_id"]))
	}
	groups, e := q.rows("select id,kode,nama from aset_m_group_aset where tenant_id=$1 and id=any($2::text[])", q.authority.tenant, ids)
	if e != nil {
		return nil, e
	}
	names := map[string]string{}
	for _, g := range groups {
		names[str(g["id"])] = str(g["kode"])
	}
	for i, d := range lines {
		b, e := q.postedBook(str(d["group_aset_id"]))
		if e != nil {
			return nil, e
		}
		if b == nil {
			problems[fmt.Sprintf("details.%d.group_aset_id", i)] = "Group " + names[str(d["group_aset_id"])] + " belum punya buku yang di-post ke finance. Tambahkan buku yang lapisan posting-nya bukan \"none\" di matriks group x buku (Master data › Group aset), lalu selesaikan lagi."
		}
	}
	return problems, nil
}
func (q *request) completeReceipt(id string) (response, error) {
	if e := q.require("aset.create"); e != nil {
		return response{}, e
	}
	h, e := q.receipt(id)
	if e != nil {
		return response{}, e
	}
	if str(h["status"]) != "draft" {
		return response{}, fail(422, "Penerimaan ini sudah diselesaikan.")
	}
	b, e := q.body()
	if e != nil {
		return response{}, e
	}
	version, e := q.expectedVersion(b)
	if e != nil {
		return response{}, e
	}
	lines, e := q.details(id, false)
	if e != nil {
		return response{}, e
	}
	if len(lines) == 0 {
		return response{}, invalid("details", "Penerimaan tanpa baris barang tidak dapat diselesaikan.")
	}
	problems, e := q.blockers(h, lines)
	if e != nil {
		return response{}, e
	}
	if len(problems) > 0 {
		errFields := object{}
		for k, v := range problems {
			errFields[k] = []string{str(v)}
		}
		return response{}, &failure{status: 422, message: str(problems[firstKey(problems)]), errors: errFields}
	}
	amount, _, e := q.precision(str(h["currency_code"]))
	if e != nil {
		return response{}, e
	}
	type unit struct {
		line                        object
		key, value, tax, code, rank string
		position                    int
	}
	units := []unit{}
	for _, d := range lines {
		if d["permintaan_pembelian_detail_id"] != nil {
			return response{}, unsupported("Purchase request terhubung belum termasuk profil ini.")
		}
		if _, e := q.postedBook(str(d["group_aset_id"])); e != nil {
			return response{}, e
		}
		n := integer(d["jumlah"])
		values := allocate(extended(d["nilai_per_unit"], n, amount), n, amount)
		taxes := allocate(extended(d["ppn_per_unit"], n, amount), n, amount)
		for i := range values {
			units = append(units, unit{line: d, key: fmt.Sprintf("penerimaan:%s:%v:%d", id, d["line_number"], i+1), value: values[i], tax: taxes[i], position: len(units)})
		}
	}
	e = q.transaction(func() error {
		if e := q.claim(id, version); e != nil {
			return e
		}
		tag, e := q.db.Exec(q.ctx, "update "+receiptTable+" set status='selesai',updated_at=now() where tenant_id=$1 and id=$2 and status='draft'", q.authority.tenant, id)
		if e != nil {
			return e
		}
		if tag.RowsAffected() == 0 {
			return fail(422, "Penerimaan ini sudah diselesaikan.")
		}
		if e = q.transaction(func() error {
			s, e := q.sequence("management-aset.asset-code")
			if e != nil {
				return e
			}
			contexts := map[string]object{}
			for i := range units {
				u := &units[i]
				contextKey := str(u.line["group_aset_id"]) + "|" + str(h["lokasi_aset_id"]) + "|" + str(h["tanggal"])
				v, ok := contexts[contextKey]
				if !ok {
					v, e = q.assetNumberValues(s, str(u.line["group_aset_id"]), str(h["lokasi_aset_id"]), str(h["tanggal"]))
					if e != nil {
						return e
					}
					contexts[contextKey] = v
				}
				u.rank = jsonText(v)
			}
			ordered := append([]unit{}, units...)
			sort.SliceStable(ordered, func(i, j int) bool { return ordered[i].rank < ordered[j].rank })
			codes := map[string]string{}
			for _, u := range ordered {
				v := object{}
				_ = decode([]byte(u.rank), &v)
				code, e := q.issue("management-aset.asset-code", "aset:"+u.key, str(h["legal_entity_id"]), v)
				if e != nil {
					return e
				}
				if len(code) > 50 {
					return invalid("reference", "Format kode aset melebihi 50 karakter.")
				}
				codes[u.key] = code
			}
			for i := range units {
				units[i].code = codes[units[i].key]
			}
			return nil
		}); e != nil {
			return e
		}
		created := []object{}
		for _, u := range units {
			if e := q.createAsset(h, u.line, u.key, u.code, u.value); e != nil {
				return e
			}
			created = append(created, object{"asset_code": u.code, "group_aset_id": u.line["group_aset_id"], "acquisition_value": u.value, "tax_amount": u.tax, "accumulated_depreciation": "0.00"})
		}
		if e := q.require("penerimaan-aset.read"); e != nil {
			return e
		}
		if _, e := q.receipt(id); e != nil {
			return e
		}
		if e = q.transaction(func() error {
			attachments, e := q.rows("select * from document_attachments where tenant_id=$1 and record_type=$2 and record_id=$3 and kind='document' and deleted_at is null order by id for update", q.authority.tenant, receiptTable, id)
			if e != nil {
				return e
			}
			if len(attachments) > 0 {
				return unsupported("Lampiran belum termasuk profil pembandingan ini.")
			}
			return nil
		}); e != nil {
			return e
		}
		_, e = q.buildPosting(h, lines, created, true)
		return e
	})
	if e != nil {
		return response{}, e
	}
	return q.showReceipt(id)
}
func firstKey(m object) string {
	keys := []string{}
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys[0]
}
func (q *request) createAsset(h, d object, key, code, value string) error {
	if e := q.condition(d); e != nil {
		return e
	}
	g, e := q.row("select kelompok_harta_fiskal_id,lokasi_aset_id,capitalization_threshold from aset_m_group_aset where tenant_id=$1 and id=$2", q.authority.tenant, d["group_aset_id"])
	if e != nil {
		return e
	}
	location := h["lokasi_aset_id"]
	if location == nil {
		location = g["lokasi_aset_id"]
	}
	dimension, e := q.inheritedLocation(str(location), "org_unit_id")
	if e != nil {
		return e
	}
	if dimension == "" {
		dimension = str(h["responsible_org_unit_id"])
	}
	id := lowerUID()
	a := object{"id": id, "tenant_id": q.authority.tenant, "creation_key": key, "kode": code, "nama": d["nama"], "legal_entity_id": h["legal_entity_id"], "responsible_org_unit_id": h["responsible_org_unit_id"], "group_aset_id": d["group_aset_id"], "kelompok_harta_fiskal_id": g["kelompok_harta_fiskal_id"], "jenis_aset_id": d["jenis_aset_id"], "kondisi_aset_id": d["kondisi_aset_id"], "pabrikan_aset_id": d["pabrikan_aset_id"], "model_aset_id": d["model_aset_id"], "model_number": d["model_number"], "lokasi_aset_id": location, "financial_dimension_org_unit_id": dimension, "serial_number": nil, "acquired_on": h["tanggal"], "placed_in_service_on": h["tanggal_siap_pakai"], "acquisition_value": value, "currency_code": h["currency_code"], "lifecycle_state": "received", "keterangan": d["keterangan"], "penerimaan_aset_id": h["id"], "penerimaan_aset_detail_id": d["id"]}
	if e = q.insert("aset_tr_aset", a); e != nil {
		return e
	}
	if e = q.insert("aset_tr_penempatan_aset", object{"id": uid(), "tenant_id": q.authority.tenant, "aset_id": id, "receiving_org_unit_id": h["receiving_org_unit_id"], "usage_org_unit_id": h["responsible_org_unit_id"], "received_by_user_id": h["diterima_oleh_user_id"], "custodian_user_id": h["penanggung_jawab_user_id"], "lokasi_aset_id": location, "effective_on": h["tanggal"], "reason": "Penerimaan aset"}); e != nil {
		return e
	}
	r, e := q.row("select kode from "+receiptTable+" where tenant_id=$1 and id=$2 and deleted_at is null", q.authority.tenant, h["id"])
	if e != nil {
		return e
	}
	if e = q.exec("select set_config('coreerp.fa_ledger_context',$1,true)", jsonText(object{"type": "acquisition", "date": h["tanggal"], "source_type": "penerimaan-aset", "source_id": h["id"], "document_number": r["kode"], "description": "Perolehan aset"})); e != nil {
		return e
	}
	books, e := q.rows("select m.buku_id,m.useful_life_periods,m.convention,m.depreciate,coalesce(m.round_off_depreciation,b.round_off_depreciation) as round_off_depreciation,coalesce(m.depreciation_profile_id,b.depreciation_profile_id) as depreciation_profile_id,coalesce(m.alternative_profile_id,b.alternative_profile_id) as alternative_profile_id,b.kode as buku_code from aset_m_group_buku_penyusutan m join aset_m_buku_penyusutan b on b.id=m.buku_id and b.tenant_id=m.tenant_id where m.tenant_id=$1 and m.group_aset_id=$2 and m.deleted_at is null and b.deleted_at is null and b.aktif=true", q.authority.tenant, d["group_aset_id"])
	if e != nil {
		return e
	}
	for _, b := range books {
		depreciates := boolean(b["depreciate"]) && (g["capitalization_threshold"] == nil || money(value).GreaterThanOrEqual(money(g["capitalization_threshold"])))
		var profile object
		if depreciates {
			profile, e = q.depreciationProfile(str(b["depreciation_profile_id"]), b["useful_life_periods"], b["convention"])
			if e != nil {
				return e
			}
			if b["alternative_profile_id"] != nil {
				if _, e = q.depreciationProfile(str(b["alternative_profile_id"]), nil, nil); e != nil {
					return e
				}
			}
		}
		life, convention := b["useful_life_periods"], b["convention"]
		if life == nil {
			life = profile["useful_life_periods"]
		}
		if convention == nil {
			convention = profile["convention"]
		}
		placed := str(h["tanggal_siap_pakai"])
		if placed == "" {
			placed = str(h["tanggal"])
		}
		start, e := depreciationStart(placed, str(convention))
		if e != nil {
			return e
		}
		if e = q.insert("aset_tr_buku_aset", object{"id": lowerUID(), "tenant_id": q.authority.tenant, "aset_id": id, "buku_id": b["buku_id"], "depreciation_profile_id": b["depreciation_profile_id"], "alternative_profile_id": b["alternative_profile_id"], "book_code": b["buku_code"], "useful_life_periods": life, "convention": convention, "depreciation_start_on": start, "depreciate": depreciates, "round_off_depreciation": b["round_off_depreciation"], "acquisition_value": value, "residual_value": d["residu_per_unit"], "accumulated_depreciation": "0", "opening_accumulated_depreciation": "0", "elapsed_periods_offset": 0, "net_book_value": value, "status": "active"}); e != nil {
			return e
		}
	}
	attributes, e := q.attributeRows(str(d["jenis_aset_id"]), objects(d["atribut"]))
	if e != nil {
		return e
	}
	ids := []string{}
	for _, a := range attributes {
		a["aset_id"] = id
		if e = q.insert("aset_tr_aset_atribut", a); e != nil {
			return e
		}
		ids = append(ids, str(a["tipe_atribut_id"]))
	}
	if len(ids) > 0 {
		return q.exec("update aset_m_tipe_atribut set data_type_locked=true,updated_at=now() where tenant_id=$1 and id=any($2::text[])", q.authority.tenant, ids)
	}
	return nil
}
func (q *request) depreciationProfile(id string, life, convention any) (object, error) {
	p, e := q.row("select id,method,frequency,convention,useful_life_periods,rate_percent,manual_schedule,effective_from,effective_to from aset_m_profil_penyusutan where tenant_id=$1 and id=$2 and aktif=true and deleted_at is null", q.authority.tenant, id)
	if errors.Is(e, pgx.ErrNoRows) {
		return nil, invalid("group_aset_id", "Profil penyusutan pada Buku atau matriks sudah tidak aktif. Pilih profil yang masih berlaku.")
	}
	if e != nil {
		return nil, e
	}
	if !contains([]string{"straight_line", "straight_line_life_remaining", "reducing_balance", "manual", "consumption"}, str(p["method"])) || !contains([]string{"monthly", "quarterly", "half_yearly", "yearly"}, str(p["frequency"])) {
		return nil, invalid("group_aset_id", "Profil penyusutan belum dapat dihitung oleh aplikasi.")
	}
	if life == nil {
		life = p["useful_life_periods"]
	}
	if convention == nil {
		convention = p["convention"]
	}
	if contains([]string{"straight_line", "straight_line_life_remaining", "reducing_balance"}, str(p["method"])) && integer(life) <= 0 {
		return nil, invalid("group_aset_id", "Masa manfaat belum diisi pada matriks atau profil penyusutan.")
	}
	if str(p["method"]) == "reducing_balance" && !money(p["rate_percent"]).IsPositive() {
		return nil, invalid("group_aset_id", "Profil saldo menurun harus memiliki persentase per tahun yang lebih besar dari 0.")
	}
	if str(p["method"]) == "manual" && len(objects(p["manual_schedule"])) == 0 {
		return nil, invalid("group_aset_id", "Profil dengan jadwal manual belum memiliki nilai penyusutan per periode.")
	}
	return p, nil
}
func contains(values []string, value string) bool {
	for _, v := range values {
		if v == value {
			return true
		}
	}
	return false
}
func depreciationStart(date, convention string) (string, error) {
	d, e := time.Parse("2006-01-02", date)
	if e != nil {
		return "", e
	}
	switch convention {
	case "full_month":
		d = time.Date(d.Year(), d.Month(), 1, 0, 0, 0, 0, time.UTC)
	case "mid_month_1st":
		offset := 0
		if d.Day() > 15 {
			offset = 1
		}
		d = time.Date(d.Year(), d.Month()+time.Month(offset), 1, 0, 0, 0, 0, time.UTC)
	case "mid_month_15th":
		d = time.Date(d.Year(), d.Month(), 15, 0, 0, 0, 0, time.UTC)
	case "none", "":
	default:
		return "", unsupported("Konvensi penyusutan ini belum termasuk profil pembandingan.")
	}
	return d.Format("2006-01-02"), nil
}
