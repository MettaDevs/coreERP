package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/oklog/ulid/v2"
)

const receiptTable = "aset_tr_penerimaan_aset"
const detailTable = "aset_tr_penerimaan_aset_details"

var headerFields = []string{"legal_entity_id", "responsible_org_unit_id", "receiving_org_unit_id", "tanggal", "tanggal_siap_pakai", "diterima_oleh_user_id", "penanggung_jawab_user_id", "lokasi_aset_id", "currency_code", "keterangan", "cara_perolehan", "vendor_id", "vendor_invoice_reference", "vendor_invoice_date", "purchase_order_reference"}
var detailFields = []string{"nama", "group_aset_id", "jenis_aset_id", "kondisi_aset_id", "pabrikan_aset_id", "model_aset_id", "model_number", "jumlah", "nilai_per_unit", "residu_per_unit", "permintaan_pembelian_detail_id", "atribut", "keterangan", "ppn_per_unit", "akumulasi_per_unit", "periode_berjalan", "saldo_awal_buku"}
var creationKeyPattern = regexp.MustCompile(`^[A-Za-z0-9._:-]{1,143}$`)
var versionPattern = regexp.MustCompile(`^\s*(?:W/)?"([0-9]+)"\s*$`)
var digitPattern = regexp.MustCompile(`^[0-9]+$`)
var integerPattern = regexp.MustCompile(`^-?[0-9]+$`)

func (q *request) receiptRoute(parts []string) (response, error) {
	m := q.r.Method
	if len(parts) == 0 {
		if m == "GET" {
			if e := q.require("penerimaan-aset.read"); e != nil {
				return response{}, e
			}
			return q.receiptList()
		}
		if m == "POST" {
			return q.createReceipt()
		}
	}
	if len(parts) == 1 {
		if m == "GET" {
			return q.showReceipt(parts[0])
		}
		if m == "PATCH" {
			return q.updateReceipt(parts[0])
		}
	}
	if len(parts) == 2 {
		switch parts[1] {
		case "selesaikan":
			if m == "POST" {
				return q.completeReceipt(parts[0])
			}
		case "pratinjau-posting":
			if m == "GET" {
				return q.previewReceipt(parts[0])
			}
		case "aset":
			if m == "GET" {
				if e := q.require("penerimaan-aset.read"); e != nil {
					return response{}, e
				}
				h, e := q.receipt(parts[0])
				if e != nil {
					return response{}, e
				}
				a, e := q.rows("select id,kode,nama,serial_number,penerimaan_aset_detail_id from aset_tr_aset where tenant_id=$1 and penerimaan_aset_id=$2 and deleted_at is null and is_budgeted=false order by kode", q.authority.tenant, parts[0])
				return response{body: object{"data": a, "version": h["version"]}}, e
			}
		}
	}
	return response{}, fail(404, "Not Found")
}
func (q *request) receipt(id string) (object, error) {
	h, e := q.row(receiptSelect+" where h.tenant_id=$1 and h.id=$2 and h.deleted_at is null", q.authority.tenant, id)
	if e != nil {
		return nil, e
	}
	if !q.authority.allows(str(h["legal_entity_id"]), str(h["responsible_org_unit_id"])) {
		return nil, pgx.ErrNoRows
	}
	return q.headerNames(h)
}

const receiptSelect = `select h.*,loc.kode as lokasi_aset_kode,loc.nama as lokasi_aset_nama,
(select count(*) from aset_tr_penerimaan_aset_details d where d.tenant_id=h.tenant_id and d.penerimaan_aset_id=h.id) as jumlah_baris,
(select coalesce(sum(jumlah),0) from aset_tr_penerimaan_aset_details d where d.tenant_id=h.tenant_id and d.penerimaan_aset_id=h.id) as jumlah_aset
from aset_tr_penerimaan_aset h left join aset_m_lokasi_aset loc on loc.id=h.lokasi_aset_id and loc.tenant_id=h.tenant_id`

func (q *request) headerNames(h object) (object, error) {
	units, e := q.rows("select o.id,o.name from organizations o join operating_units u on u.organization_id=o.id where o.tenant_id=$1", q.authority.tenant)
	if e != nil {
		return nil, e
	}
	names := map[string]string{}
	for _, u := range units {
		names[str(u["id"])] = str(u["name"])
	}
	h["responsible_org_unit_nama"] = nullable(names[str(h["responsible_org_unit_id"])])
	h["receiving_org_unit_nama"] = nullable(names[str(h["receiving_org_unit_id"])])
	h["diterima_oleh_nama"] = nil
	h["penanggung_jawab_nama"] = nil
	if h["diterima_oleh_user_id"] != nil || h["penanggung_jawab_user_id"] != nil {
		members, e := q.rows("select m.user_id,u.name from tenant_memberships m join users u on u.id=m.user_id where m.tenant_id=$1", q.authority.tenant)
		if e != nil {
			return nil, e
		}
		for _, m := range members {
			if str(m["user_id"]) == str(h["diterima_oleh_user_id"]) {
				h["diterima_oleh_nama"] = m["name"]
			}
			if str(m["user_id"]) == str(h["penanggung_jawab_user_id"]) {
				h["penanggung_jawab_nama"] = m["name"]
			}
		}
	}
	return h, nil
}
func (q *request) details(id string, lookups bool) ([]object, error) {
	if !lookups {
		return q.rows("select * from "+detailTable+" where tenant_id=$1 and penerimaan_aset_id=$2 order by line_number", q.authority.tenant, id)
	}
	return q.rows(`select d.*,g.nama as group_aset_nama,t.nama as jenis_aset_nama,c.nama as kondisi_aset_nama,p.nama as pabrikan_aset_nama,m.nama as model_aset_nama,pr.id as purchase_request_id,pr.kode as purchase_request_code
from aset_tr_penerimaan_aset_details d
left join aset_tr_permintaan_pengadaan_aset_details rl on rl.id=d.permintaan_pembelian_detail_id and rl.tenant_id=d.tenant_id
left join aset_tr_permintaan_pengadaan_aset pr on pr.id=rl.request_id and pr.tenant_id=d.tenant_id
left join aset_m_group_aset g on g.id=d.group_aset_id and g.tenant_id=d.tenant_id
left join aset_m_jenis_aset t on t.id=d.jenis_aset_id and t.tenant_id=d.tenant_id
left join aset_m_kondisi_aset c on c.id=d.kondisi_aset_id and c.tenant_id=d.tenant_id
left join aset_m_pabrikan_aset p on p.id=d.pabrikan_aset_id and p.tenant_id=d.tenant_id
left join aset_m_model_aset m on m.id=d.model_aset_id and m.tenant_id=d.tenant_id
where d.tenant_id=$1 and d.penerimaan_aset_id=$2 order by d.line_number`, q.authority.tenant, id)
}
func (q *request) vendor(id string) (object, error) {
	if id == "" {
		return nil, nil
	}
	v, e := q.row("select * from vendors where tenant_id=$1 and id=$2", q.authority.tenant, id)
	if errors.Is(e, pgx.ErrNoRows) {
		return nil, nil
	}
	if e != nil {
		return nil, e
	}
	p, e := q.row("select id,name from parties where id=$1 and deleted_at is null", v["party_id"])
	if e != nil {
		return nil, e
	}
	return object{"id": v["id"], "number": v["number"], "name": p["name"], "tax_number": v["tax_number"], "status": v["status"], "legal_entity_id": v["legal_entity_id"]}, nil
}
func (q *request) postingStatus(id string) (object, error) {
	r, e := q.row("select posting_id,status,settlement_mode,posting_date,manual_reason,external_reference,reason_code,reason,acknowledged_at,hold_reasons as problems from finance_postings where tenant_id=$1 and posting_id=$2", q.authority.tenant, id)
	if errors.Is(e, pgx.ErrNoRows) {
		return nil, nil
	}
	if r != nil && r["problems"] == nil {
		r["problems"] = []any{}
	}
	return r, e
}
func (q *request) showReceipt(id string) (response, error) {
	if e := q.require("penerimaan-aset.read"); e != nil {
		return response{}, e
	}
	h, e := q.receipt(id)
	if e != nil {
		return response{}, e
	}
	d, e := q.details(id, true)
	if e != nil {
		return response{}, e
	}
	h["details"] = d
	vendor, e := q.vendor(str(h["vendor_id"]))
	if e != nil {
		return response{}, e
	}
	h["vendor"] = nil
	if vendor != nil {
		h["vendor"] = take(vendor, "id", "number", "name", "status")
	}
	h["posting"] = nil
	if str(h["status"]) == "selesai" || str(h["status"]) == "cancelled" {
		h["posting"], e = q.postingStatus("AST-ACQ-" + id)
		if e != nil {
			return response{}, e
		}
	}
	c, e := q.row("select * from aset_tr_pembatalan where tenant_id=$1 and resource='penerimaan-aset' and document_id=$2 and deleted_at is null order by created_at desc,id desc limit 1", q.authority.tenant, id)
	if e != nil && !errors.Is(e, pgx.ErrNoRows) {
		return response{}, e
	}
	h["cancellation"] = nil
	if c != nil {
		return response{}, unsupported("Pembatalan berada di luar profil pembandingan ini.")
	}
	return response{body: object{"data": h}, headers: http.Header{"ETag": []string{fmt.Sprintf("W/\"%d\"", integer(h["version"]))}}}, nil
}
func (q *request) validatedReceipt(b object) (object, []object, error) {
	h := take(b, headerFields...)
	for k, v := range h {
		h[k] = nullable(v)
	}
	for key, maximum := range map[string]int{"legal_entity_id": 26, "responsible_org_unit_id": 26, "receiving_org_unit_id": 26, "lokasi_aset_id": 26, "vendor_id": 26, "tanggal": 10, "tanggal_siap_pakai": 10, "vendor_invoice_date": 10, "currency_code": 3, "diterima_oleh_user_id": 64, "penanggung_jawab_user_id": 64, "keterangan": 2000, "vendor_invoice_reference": 80, "purchase_order_reference": 80, "cara_perolehan": 40} {
		if h[key] != nil {
			value, ok := h[key].(string)
			if !ok || len([]rune(value)) > maximum {
				return nil, nil, invalid(key, "Isian tidak valid atau terlalu panjang.")
			}
		}
	}
	for _, key := range []string{"responsible_org_unit_id", "receiving_org_unit_id", "lokasi_aset_id", "vendor_id"} {
		if h[key] != nil {
			if _, e := ulid.Parse(strings.ToUpper(str(h[key]))); e != nil {
				return nil, nil, invalid(key, "ID tidak valid.")
			}
		}
	}
	if h["cara_perolehan"] == nil {
		h["cara_perolehan"] = "pembelian"
	}
	if str(h["cara_perolehan"]) != "pembelian" {
		return nil, nil, unsupported("Profil pembandingan ini memakai penerimaan pembelian.")
	}
	if _, e := ulid.Parse(strings.ToUpper(str(h["legal_entity_id"]))); e != nil {
		return nil, nil, invalid("legal_entity_id", "Perusahaan wajib dipilih.")
	}
	for _, f := range []string{"tanggal", "tanggal_siap_pakai", "vendor_invoice_date"} {
		if h[f] != nil {
			d, e := time.Parse("2006-01-02", str(h[f]))
			if e != nil || d.Format("2006-01-02") != str(h[f]) {
				return nil, nil, invalid(f, "Tanggal harus memakai YYYY-MM-DD.")
			}
		}
	}
	if h["tanggal"] == nil {
		return nil, nil, invalid("tanggal", "Tanggal wajib diisi.")
	}
	if h["tanggal_siap_pakai"] != nil && str(h["tanggal_siap_pakai"]) < str(h["tanggal"]) {
		return nil, nil, invalid("tanggal_siap_pakai", "Tanggal siap pakai tidak boleh sebelum tanggal penerimaan.")
	}
	h["currency_code"] = strings.ToUpper(str(h["currency_code"]))
	if len(str(h["currency_code"])) != 3 {
		return nil, nil, invalid("currency_code", "Kode mata uang harus 3 karakter.")
	}
	h["purchase_order_reference"] = nullable(strings.TrimSpace(str(h["purchase_order_reference"])))
	if h["responsible_org_unit_id"] == nil {
		id, e := q.inheritedLocation(str(h["lokasi_aset_id"]), "departemen_bawaan_id")
		if e != nil {
			return nil, nil, e
		}
		h["responsible_org_unit_id"] = nullable(id)
	}
	if h["responsible_org_unit_id"] == nil {
		return nil, nil, invalid("responsible_org_unit_id", "Pilih unit penanggung jawab, atau pilih lokasi yang punya unit kerja bawaan.")
	}
	_, precision, e := q.precision(str(h["currency_code"]))
	if e != nil {
		return nil, nil, e
	}
	details := objects(b["details"])
	if len(details) == 0 {
		return nil, nil, invalid("details", "Penerimaan harus berisi baris barang.")
	}
	for i, d := range details {
		d = take(d, detailFields...)
		for k, v := range d {
			d[k] = nullable(v)
		}
		details[i] = d
		prefix := fmt.Sprintf("details.%d.", i)
		for key, maximum := range map[string]int{"nama": 150, "model_number": 150, "keterangan": 2000} {
			if d[key] != nil {
				value, ok := d[key].(string)
				if !ok || len([]rune(value)) > maximum {
					return nil, nil, invalid(prefix+key, "Isian tidak valid atau terlalu panjang.")
				}
			}
		}
		if str(d["nama"]) == "" || len([]rune(str(d["nama"]))) > 150 {
			return nil, nil, invalid(prefix+"nama", "Nama wajib diisi dan paling panjang 150 karakter.")
		}
		count := integer(d["jumlah"])
		if count < 1 || count > 500 || str(d["jumlah"]) != fmt.Sprint(count) {
			return nil, nil, invalid(prefix+"jumlah", "Jumlah harus bilangan bulat 1 sampai 500.")
		}
		d["jumlah"] = count
		for _, f := range []string{"nilai_per_unit", "ppn_per_unit", "residu_per_unit", "akumulasi_per_unit"} {
			if f == "nilai_per_unit" && d[f] == nil {
				return nil, nil, invalid(prefix+f, "Nilai per unit wajib diisi.")
			}
			v, e := decimalValue(d[f])
			if e != nil || v.IsNegative() {
				return nil, nil, invalid(prefix+f, "Nilai harus berupa angka tidak negatif.")
			}
			d[f] = v.String()
			if (f == "nilai_per_unit" || f == "ppn_per_unit") && !v.Equal(v.Truncate(precision)) {
				return nil, nil, invalid(prefix+f, fmt.Sprintf("Paling banyak %d angka di belakang koma untuk %s.", precision, h["currency_code"]))
			}
		}
		if !money(d["akumulasi_per_unit"]).IsZero() || integer(d["periode_berjalan"]) > 0 || len(objects(d["saldo_awal_buku"])) > 0 {
			return nil, nil, invalid(prefix+"akumulasi_per_unit", "Akumulasi dan periode berjalan hanya diisi untuk saldo awal.")
		}
		d["periode_berjalan"] = 0
		d["saldo_awal_buku"] = nil
		if d["permintaan_pembelian_detail_id"] != nil {
			return nil, nil, unsupported("Purchase request terhubung belum termasuk profil pembandingan ini.")
		}
		for f, table := range map[string]string{"group_aset_id": "aset_m_group_aset", "jenis_aset_id": "aset_m_jenis_aset", "kondisi_aset_id": "aset_m_kondisi_aset", "pabrikan_aset_id": "aset_m_pabrikan_aset", "model_aset_id": "aset_m_model_aset"} {
			if d[f] == nil && f != "group_aset_id" && f != "jenis_aset_id" {
				continue
			}
			if _, e := q.row("select id from "+table+" where tenant_id=$1 and id=$2 and deleted_at is null", q.authority.tenant, d[f]); errors.Is(e, pgx.ErrNoRows) {
				return nil, nil, invalid(prefix+f, "Pilihan tidak tersedia.")
			} else if e != nil {
				return nil, nil, e
			}
		}
		if e = q.condition(d); e != nil {
			var validation *failure
			if errors.As(e, &validation) && validation.errors != nil {
				prefixed := object{}
				for key, value := range validation.errors {
					prefixed[prefix+key] = value
				}
				validation.errors = prefixed
			}
			return nil, nil, e
		}
		if _, e = q.attributeRows(str(d["jenis_aset_id"]), objects(d["atribut"])); e != nil {
			var validation *failure
			if errors.As(e, &validation) && validation.errors != nil {
				prefixed := object{}
				for key, value := range validation.errors {
					prefixed[prefix+key] = value
				}
				validation.errors = prefixed
			}
			return nil, nil, e
		}
		if d["model_aset_id"] != nil {
			return nil, nil, unsupported("Model aset belum termasuk profil pembandingan ini.")
		}
		if d["atribut"] == nil {
			d["atribut"] = []object{}
		}
	}
	if h["lokasi_aset_id"] != nil {
		if _, e = q.row("select id from aset_m_lokasi_aset where tenant_id=$1 and id=$2 and deleted_at is null", q.authority.tenant, h["lokasi_aset_id"]); e != nil {
			return nil, nil, invalid("lokasi_aset_id", "Lokasi tidak tersedia.")
		}
	}
	if !q.authority.allows(str(h["legal_entity_id"]), str(h["responsible_org_unit_id"])) {
		return nil, nil, fail(403, "Forbidden")
	}
	if h["vendor_id"] != nil {
		v, e := q.vendor(str(h["vendor_id"]))
		if e != nil {
			return nil, nil, e
		}
		if v == nil || str(v["legal_entity_id"]) != str(h["legal_entity_id"]) {
			return nil, nil, invalid("vendor_id", "Pilih vendor dari perusahaan yang sama.")
		}
	}
	return h, details, nil
}
func (q *request) createReceipt() (response, error) {
	if e := q.require("penerimaan-aset.create"); e != nil {
		return response{}, e
	}
	key := q.r.Header.Get("Idempotency-Key")
	if !creationKeyPattern.MatchString(key) {
		return response{}, invalid("key", "Idempotency-Key wajib diisi dengan format yang sah.")
	}
	r, e := q.row("select * from "+receiptTable+" where tenant_id=$1 and creation_key=$2 limit 1", q.authority.tenant, key)
	if e == nil {
		if !q.authority.allows(str(r["legal_entity_id"]), str(r["responsible_org_unit_id"])) {
			return response{}, fail(403, "Forbidden")
		}
		return response{body: object{"data": r}, headers: http.Header{"Idempotent-Replayed": []string{"true"}}}, nil
	}
	if !errors.Is(e, pgx.ErrNoRows) {
		return response{}, e
	}
	b, e := q.body()
	if e != nil {
		return response{}, e
	}
	h, details, e := q.validatedReceipt(b)
	if e != nil {
		return response{}, e
	}
	code, e := q.issue("management-aset.penerimaan-aset", "penerimaan-aset:"+key, str(h["legal_entity_id"]), nil)
	if e != nil {
		return response{}, e
	}
	id := uid()
	h["id"] = id
	h["tenant_id"] = q.authority.tenant
	h["creation_key"] = key
	h["kode"] = code
	h["status"] = "draft"
	e = q.transaction(func() error {
		if e := q.insert(receiptTable, h); e != nil {
			return e
		}
		return q.replaceDetails(id, details)
	})
	if e != nil {
		var p *pgconn.PgError
		if errors.As(e, &p) {
			r, re := q.row("select * from "+receiptTable+" where tenant_id=$1 and creation_key=$2 limit 1", q.authority.tenant, key)
			if re == nil {
				if !q.authority.allows(str(r["legal_entity_id"]), str(r["responsible_org_unit_id"])) {
					return response{}, fail(403, "Forbidden")
				}
				return response{body: object{"data": r}, headers: http.Header{"Idempotent-Replayed": []string{"true"}}}, nil
			}
		}
		return response{}, e
	}
	h, e = q.receipt(id)
	if e != nil {
		return response{}, e
	}
	h["details"], e = q.details(id, true)
	scheme := "http"
	if q.r.TLS != nil {
		scheme = "https"
	}
	return response{status: 201, body: object{"data": h}, headers: http.Header{"Location": []string{scheme + "://" + q.r.Host + q.r.URL.Path + "/" + id}}}, e
}
func (q *request) replaceDetails(id string, details []object) error {
	for i, d := range details {
		d = take(d, detailFields...)
		d["id"] = lowerUID()
		d["tenant_id"] = q.authority.tenant
		d["penerimaan_aset_id"] = id
		d["line_number"] = i + 1
		d["atribut"] = jsonText(d["atribut"])
		if d["saldo_awal_buku"] != nil {
			d["saldo_awal_buku"] = jsonText(d["saldo_awal_buku"])
		}
		if e := q.insert(detailTable, d); e != nil {
			return e
		}
	}
	return nil
}
func (q *request) expectedVersion(b object) (int64, error) {
	if h, exists := q.r.Header["If-Match"]; exists {
		header := ""
		if len(h) > 0 {
			header = h[0]
		}
		v := versionPattern.FindStringSubmatch(header)
		if v != nil {
			return integer(v[1]), nil
		}
		return 0, versionRequired()
	}
	switch v := b["version"].(type) {
	case json.Number:
		if integerPattern.MatchString(string(v)) {
			return integer(v), nil
		}
	case string:
		if digitPattern.MatchString(v) {
			return integer(v), nil
		}
	}
	return 0, versionRequired()
}
func versionRequired() error {
	return &failure{status: 428, code: "version_required", message: "Versi data yang sedang diubah wajib dikirim: header If-Match berisi ETag, atau field version."}
}
func (q *request) claim(id string, version int64) error {
	tag, e := q.db.Exec(q.ctx, "update "+receiptTable+" set version=version where tenant_id=$1 and id=$2 and deleted_at is null and version=$3", q.authority.tenant, id, version)
	if e != nil {
		return e
	}
	if tag.RowsAffected() == 0 {
		if _, err := q.row("select id from "+receiptTable+" where tenant_id=$1 and id=$2 and deleted_at is null", q.authority.tenant, id); err != nil {
			return err
		}
		return &failure{status: 409, code: "stale_version", message: "Perubahanmu belum disimpan karena data ini sudah diubah sejak kamu membukanya. Muat ulang untuk melihat data terbaru, lalu ulangi perubahanmu."}
	}
	return nil
}
func (q *request) updateReceipt(id string) (response, error) {
	if e := q.require("penerimaan-aset.update"); e != nil {
		return response{}, e
	}
	h, e := q.receipt(id)
	if e != nil {
		return response{}, e
	}
	if str(h["status"]) != "draft" {
		return response{}, fail(422, "Penerimaan yang sudah selesai tidak dapat diubah; asetnya sudah terdaftar dan bernomor.")
	}
	b, e := q.body()
	if e != nil {
		return response{}, e
	}
	h, d, e := q.validatedReceipt(b)
	if e != nil {
		return response{}, e
	}
	version, e := q.expectedVersion(b)
	if e != nil {
		return response{}, e
	}
	e = q.transaction(func() error {
		if e := q.claim(id, version); e != nil {
			return e
		}
		keys := make([]string, 0, len(h))
		for k := range h {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		args := []any{q.authority.tenant, id}
		sets := []string{}
		for _, k := range keys {
			args = append(args, h[k])
			sets = append(sets, fmt.Sprintf("%s=$%d", k, len(args)))
		}
		if e := q.exec("update "+receiptTable+" set "+strings.Join(sets, ",")+",updated_at=now() where tenant_id=$1 and id=$2 and status='draft'", args...); e != nil {
			return e
		}
		if e := q.exec("delete from "+detailTable+" where tenant_id=$1 and penerimaan_aset_id=$2", q.authority.tenant, id); e != nil {
			return e
		}
		return q.replaceDetails(id, d)
	})
	if e != nil {
		return response{}, e
	}
	return q.showReceipt(id)
}
func (q *request) inheritedLocation(id, column string) (string, error) {
	visited := map[string]bool{}
	for depth := 0; id != "" && depth < 32 && !visited[id]; depth++ {
		visited[id] = true
		r, e := q.row("select parent_id,"+column+" from aset_m_lokasi_aset where tenant_id=$1 and id=$2", q.authority.tenant, id)
		if errors.Is(e, pgx.ErrNoRows) {
			return "", nil
		}
		if e != nil {
			return "", e
		}
		if r[column] != nil {
			return str(r[column]), nil
		}
		id = str(r["parent_id"])
	}
	return "", nil
}
func (q *request) condition(d object) error {
	if d["kondisi_aset_id"] == nil {
		return nil
	}
	return unsupported("Kondisi aset belum termasuk profil pembandingan ini.")
}
