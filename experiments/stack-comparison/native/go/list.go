package main

import (
	"encoding/base64"
	"fmt"
	"regexp"
	"slices"
	"strings"
	"time"

	"golang.org/x/text/unicode/norm"
)

type sqlFilter struct {
	clauses []string
	args    []any
}

func (f *sqlFilter) bind(v any) string {
	f.args = append(f.args, v)
	return fmt.Sprintf("$%d", len(f.args))
}
func (f *sqlFilter) add(sql string, values ...any) {
	for _, v := range values {
		sql = strings.Replace(sql, "?", f.bind(v), 1)
	}
	f.clauses = append(f.clauses, sql)
}
func (f *sqlFilter) where() string {
	if len(f.clauses) == 0 {
		return ""
	}
	return " where " + strings.Join(f.clauses, " and ")
}
func (q *request) scopeFilter(f *sqlFilter, alias string) {
	a := q.authority
	f.add(alias+".tenant_id=?", a.tenant)
	f.add(alias + ".deleted_at is null")
	f.add(alias+".legal_entity_id=?", a.company)
	if a.all {
		return
	}
	grants := []string{}
	for _, g := range a.grants {
		parts := []string{}
		if g.company != "" {
			parts = append(parts, alias+".legal_entity_id="+f.bind(g.company))
		}
		if len(g.units) > 0 {
			ids := []string{}
			for id := range g.units {
				ids = append(ids, id)
			}
			parts = append(parts, alias+".responsible_org_unit_id=any("+f.bind(ids)+")")
		}
		if len(parts) > 0 {
			grants = append(grants, "("+strings.Join(parts, " and ")+")")
		}
	}
	if len(grants) == 0 {
		f.add("false")
	} else {
		f.add("(" + strings.Join(grants, " or ") + ")")
	}
}
func (q *request) receiptList() (response, error) {
	f := &sqlFilter{}
	q.scopeFilter(f, "h")
	query := q.r.URL.Query()
	if status := query.Get("status"); status != "" {
		if !contains([]string{"draft", "selesai", "cancelled"}, status) {
			return response{}, invalid("status", "Status tidak valid.")
		}
		f.add("h.status=?", status)
	}
	for _, date := range []string{"dari", "sampai"} {
		if v := query.Get(date); v != "" {
			if _, e := time.Parse("2006-01-02", v); e != nil {
				return response{}, invalid(date, "Tanggal tidak valid.")
			}
			op := ">="
			if date == "sampai" {
				op = "<="
				if query.Get("dari") != "" && v < query.Get("dari") {
					return response{}, invalid(date, "Tanggal akhir sebelum tanggal awal.")
				}
			}
			f.add("h.tanggal"+op+"?", v)
		}
	}
	base := `select x.*,
(select name from organizations where tenant_id=x.tenant_id and id=x.legal_entity_id) as legal_entity_nama,
(select name from organizations where tenant_id=x.tenant_id and id=x.responsible_org_unit_id) as responsible_org_unit_nama,
(select name from organizations where tenant_id=x.tenant_id and id=x.receiving_org_unit_id) as receiving_org_unit_nama,
(select u.name from tenant_memberships m join users u on u.id=m.user_id where m.tenant_id=x.tenant_id and m.user_id::text=x.diterima_oleh_user_id limit 1) as diterima_oleh_nama,
(select u.name from tenant_memberships m join users u on u.id=m.user_id where m.tenant_id=x.tenant_id and m.user_id::text=x.penanggung_jawab_user_id limit 1) as penanggung_jawab_nama
from (` + receiptSelect + f.where() + `) x`
	return q.paginate(base, f.args, map[string]string{"id": "id", "legal_entity": "legal_entity_nama", "kode": "kode", "tanggal": "tanggal", "siap": "tanggal_siap_pakai", "unit": "responsible_org_unit_nama", "lokasi": "lokasi_aset_nama", "baris": "jumlah_baris", "jumlah": "jumlah_aset", "status": "status"}, []string{"kode", "legal_entity_nama", "keterangan", "lokasi_aset_nama", "responsible_org_unit_nama", "status"}, "tanggal", []string{"jumlah_baris", "jumlah_aset"}, false)
}
func (q *request) assetList() (response, error) {
	if q.r.URL.Query().Get("planning_company_id") != "" {
		return response{}, unsupported("Planning company lookup belum termasuk profil pembandingan.")
	}
	f := &sqlFilter{}
	q.scopeFilter(f, "a")
	states := []string{"received", "in_use", "under_maintenance", "idle", "disposed", "sold", "scrapped", "retired"}
	for _, field := range []string{"lifecycle_state", "exclude_state"} {
		if value := q.r.URL.Query().Get(field); value != "" {
			if !contains(states, value) {
				return response{}, invalid(field, "Status tidak valid.")
			}
			op := "="
			if field == "exclude_state" {
				op = "<>"
			}
			f.add("a.lifecycle_state"+op+"?", value)
		}
	}
	base := `select a.*,g.nama as group_nama,t.nama as type_nama,l.nama as location_nama,
(select name from organizations where tenant_id=a.tenant_id and id=a.legal_entity_id) as legal_entity_nama
from aset_tr_aset a left join aset_m_group_aset g on g.id=a.group_aset_id and g.tenant_id=a.tenant_id
left join aset_m_jenis_aset t on t.id=a.jenis_aset_id and t.tenant_id=a.tenant_id
left join aset_m_lokasi_aset l on l.id=a.lokasi_aset_id and l.tenant_id=a.tenant_id` + f.where()
	return q.paginate(base, f.args, map[string]string{"id": "id", "created_at": "created_at", "kode": "kode", "nama": "nama", "serial": "serial_number", "legal_entity": "legal_entity_nama", "group": "group_nama", "jenis": "type_nama", "lokasi": "location_nama", "nilai": "acquisition_value", "status": "lifecycle_state"}, []string{"kode", "nama", "serial_number"}, "created_at", []string{"acquisition_value"}, true)
}

var searchTerms = regexp.MustCompile(`"([^"]*)"|([^\s"]+)`)
var searchWords = regexp.MustCompile(`[\p{L}\p{N}]+`)

func searchFilter(f *sqlFilter, keyword string, columns []string) error {
	if len([]rune(keyword)) > 200 {
		return invalid("q", "Gunakan paling banyak 200 karakter untuk pencarian.")
	}
	keyword = strings.TrimSpace(strings.ToLower(norm.NFC.String(keyword)))
	if keyword == "" {
		return nil
	}
	terms := searchTerms.FindAllStringSubmatch(keyword, -1)
	hasTerms := false
	for _, term := range terms {
		text := term[2]
		phrase := term[1] != ""
		if phrase {
			text = term[1]
		}
		words := searchWords.FindAllString(text, -1)
		if len(words) == 0 {
			continue
		}
		hasTerms = true
		query := strings.Join(words, " ")
		fn := "phraseto_tsquery"
		if !phrase {
			prefix := []string{}
			for _, w := range words {
				prefix = append(prefix, "'"+w+"':*")
			}
			query = strings.Join(prefix, " & ")
			fn = "to_tsquery"
		}
		parameter := f.bind(query)
		matches := []string{}
		for _, c := range columns {
			matches = append(matches, "to_tsvector('simple'::regconfig,regexp_replace(normalize(coalesce("+c+"::text,''),NFC),'[^[:alnum:]]+',' ','g')) @@ "+fn+"('simple'::regconfig,"+parameter+")")
		}
		f.add("(" + strings.Join(matches, " or ") + ")")
	}
	if !hasTerms {
		f.add("false")
	}
	return nil
}
func (q *request) paginate(base string, args []any, columns map[string]string, search []string, defaultSort string, numeric []string, assets bool) (response, error) {
	query := q.r.URL.Query()
	key := query.Get("sort")
	if key == "" {
		key = defaultSort
	}
	column, ok := columns[key]
	if !ok {
		return response{}, invalid("sort", "Urutan tidak valid.")
	}
	direction := query.Get("direction")
	if direction == "" {
		direction = "asc"
		if query.Get("sort") == "" {
			direction = "desc"
		}
	}
	if direction != "asc" && direction != "desc" {
		return response{}, invalid("direction", "Arah urutan tidak valid.")
	}
	size := int64(40)
	if query.Get("per_page") != "" {
		size = integer(query.Get("per_page"))
		if size < 1 || size > 100 {
			return response{}, invalid("per_page", "Jumlah baris harus 1 sampai 100.")
		}
	}
	f := &sqlFilter{args: args}
	if id := query.Get("record_id"); id != "" {
		if len(id) > 100 {
			return response{}, invalid("record_id", "ID terlalu panjang.")
		}
		f.add("id=?", id)
	}
	recordIDs, hasRecordIDs := query["record_ids[]"]
	if !hasRecordIDs {
		recordIDs, hasRecordIDs = query["record_ids"]
	}
	if hasRecordIDs {
		if len(recordIDs) > 100 {
			return response{}, invalid("record_ids", "Paling banyak 100 ID.")
		}
		f.add("id=any(?)", recordIDs)
	}
	if e := searchFilter(f, query.Get("q"), search); e != nil {
		return response{}, e
	}
	value := "coalesce(" + column + "::text,'')"
	isNumeric := contains(numeric, column)
	if isNumeric {
		value = "coalesce(" + column + ",0)"
	}
	base = "select list_records.*,case when " + column + " is null then 1 else 0 end as list_sort_null," + value + " as list_sort_value from (" + base + ") list_records" + f.where()
	cursor := object{}
	forward := true
	where := ""
	if encoded := query.Get("cursor"); encoded != "" {
		if len(encoded) > 2000 {
			return response{}, invalid("cursor", "Posisi daftar tidak valid. Muat ulang daftar.")
		}
		bytes, e := base64.RawURLEncoding.DecodeString(strings.TrimRight(encoded, "="))
		if e != nil || decode(bytes, &cursor) != nil {
			return response{}, invalid("cursor", "Posisi daftar tidak valid. Muat ulang daftar.")
		}
		if cursor["list_sort_null"] == nil || (integer(cursor["list_sort_null"]) != 0 && integer(cursor["list_sort_null"]) != 1) || cursor["list_sort_value"] == nil || (column != "id" && cursor["id"] == nil) {
			return response{}, invalid("cursor", "Posisi daftar tidak valid. Muat ulang daftar.")
		}
		if v, ok := cursor["_pointsToNextItems"].(bool); ok {
			forward = v
		}
		if isNumeric {
			if _, e := decimalValue(cursor["list_sort_value"]); e != nil {
				return response{}, invalid("cursor", "Posisi daftar tidak valid. Muat ulang daftar.")
			}
		}
		valueOp, identityOp := ">", ">"
		nullOp := ">"
		if (direction == "desc") == forward {
			valueOp = "<"
			identityOp = "<"
		}
		if !forward {
			nullOp = "<"
		}
		null := f.bind(integer(cursor["list_sort_null"]))
		v := f.bind(cursor["list_sort_value"])
		where = " where (list_sort_null" + nullOp + null + " or (list_sort_null=" + null + " and (list_sort_value" + valueOp + v
		if column != "id" {
			id := f.bind(cursor["id"])
			where += " or (list_sort_value=" + v + " and id" + identityOp + id + ")"
		}
		where += ")))"
	}
	order := direction
	nullOrder := "asc"
	if !forward {
		nullOrder = "desc"
		if order == "asc" {
			order = "desc"
		} else {
			order = "asc"
		}
	}
	orderSQL := " order by list_sort_null " + nullOrder + ",list_sort_value " + order
	if column != "id" {
		orderSQL += ",id " + order
	}
	result, e := q.rows("select * from ("+base+") sorted_records"+where+orderSQL+fmt.Sprintf(" limit %d", size+1), f.args...)
	if e != nil {
		return response{}, e
	}
	more := len(result) > int(size)
	if more {
		result = result[:size]
	}
	if !forward {
		slices.Reverse(result)
	}
	var next any
	if len(result) > 0 && (more || !forward) {
		last := result[len(result)-1]
		v := last["list_sort_value"]
		cursor := object{"list_sort_null": last["list_sort_null"], "list_sort_value": v, "_pointsToNextItems": true}
		if column != "id" {
			cursor["id"] = last["id"]
		}
		next = base64.RawURLEncoding.EncodeToString([]byte(jsonText(cursor)))
	}
	for i, r := range result {
		delete(r, "list_sort_null")
		delete(r, "list_sort_value")
		if assets {
			out := presentAsset(r)
			for _, field := range []string{"legal_entity_nama", "group_nama", "type_nama", "location_nama"} {
				out[field] = r[field]
			}
			result[i] = out
		}
	}
	return response{body: object{"data": result, "next_cursor": next}}, nil
}
