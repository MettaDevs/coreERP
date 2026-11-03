package main

import (
	"crypto/sha256"
	"errors"
	"fmt"
	"regexp"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
)

var contextCode = regexp.MustCompile(`^[A-Za-z0-9_-]+$`)

func (q *request) sequence(reference string) (object, error) {
	return q.row("select s.* from tenant_number_sequences s where s.tenant_id=$1 and s.status='active' and exists(select 1 from app_number_sequence_references r where r.id=s.reference_id and r.code=$2 and r.app_id='management-aset') for share", q.authority.tenant, reference)
}
func (q *request) issue(reference, key, company string, values object) (string, error) {
	var number string
	for attempt := 0; attempt < 3; attempt++ {
		err := q.transaction(func() error { var err error; number, err = q.issueNumber(reference, key, company, values); return err })
		if err == nil {
			return number, nil
		}
		var p *pgconn.PgError
		if !errors.As(err, &p) || p.Code != "23505" || attempt == 2 {
			var validation *failure
			if errors.As(err, &validation) && validation.code == "" {
				return "", &failure{status: 422, code: "number_sequence_failed", message: validation.message}
			}
			return "", err
		}
	}
	return "", errors.New("number issue retries exhausted")
}
func (q *request) issueNumber(reference, key, company string, values object) (string, error) {
	s, e := q.sequence(reference)
	if errors.Is(e, pgx.ErrNoRows) {
		return "", invalid("reference", "Sequence aktif tidak ditemukan untuk aplikasi dan tenant ini.")
	}
	if e != nil {
		return "", e
	}
	if boolean(s["is_continuous"]) || !boolean(s["allow_automatic"]) {
		return "", invalid("reference", "Sequence ini tidak mengizinkan penerbitan otomatis langsung.")
	}
	scope := "tenant:" + q.authority.tenant
	scopeCode := ""
	if str(s["scope_type"]) != "tenant" {
		if str(s["scope_type"]) != "legal_entity" {
			return "", unsupported("Profil ini belum mencakup nomor dengan scope operating unit.")
		}
		o, e := q.row("select o.*,l.company_code from organizations o left join legal_entities l on l.organization_id=o.id where o.id=$1 and o.tenant_id=$2 and o.classification='legal_entity' and o.status='active'", company, q.authority.tenant)
		if errors.Is(e, pgx.ErrNoRows) {
			return "", invalid("scope", "Context organisasi tidak sah untuk sequence ini.")
		}
		if e != nil {
			return "", e
		}
		scope = "legal_entity:" + company
		scopeCode = str(o["company_code"])
	}
	segments := objects(s["segments"])
	used := object{}
	for _, seg := range segments {
		if str(seg["type"]) == "context" {
			v := str(values[str(seg["value"])])
			if !contextCode.MatchString(v) || int64(len(v)) > integer(seg["length"]) {
				return "", invalid("values", "Lengkapi kode untuk "+str(seg["name"])+" sebelum menerbitkan nomor.")
			}
			used[str(seg["value"])] = v
		}
	}
	if len(used) > 0 {
		scope += fmt.Sprintf("|values:%x", sha256.Sum256([]byte(jsonText(used))))
	}
	period := "all"
	if str(s["reset_period"]) == "calendar_year" {
		period = time.Now().UTC().Format("2006")
	}
	if strings.HasPrefix(str(s["reset_period"]), "fiscal") {
		return "", unsupported("Reset fiskal belum termasuk profil perbandingan ini.")
	}
	issue, e := q.row("select * from number_sequence_issues where sequence_id=$1 and app_id='management-aset' and idempotency_key=$2 limit 1", s["id"], key)
	if e == nil {
		return str(issue["formatted_value"]), nil
	}
	if !errors.Is(e, pgx.ErrNoRows) {
		return "", e
	}
	for {
		n, e := q.nextNumber(s, scope, period)
		if e != nil {
			return "", e
		}
		var builder strings.Builder
		for _, seg := range segments {
			switch str(seg["type"]) {
			case "constant":
				builder.WriteString(str(seg["value"]))
			case "context":
				builder.WriteString(str(used[str(seg["value"])]))
			case "scope":
				builder.WriteString(scopeCode)
			case "year":
				builder.WriteString(time.Now().UTC().Format("2006"))
			case "number":
				digits := fmt.Sprint(n)
				length := int(integer(seg["length"]))
				if len(digits) > length {
					return "", invalid("reference", "Nomor sudah melampaui panjang segmen yang dikonfigurasi.")
				}
				builder.WriteString(strings.Repeat("0", length-len(digits)) + digits)
			default:
				return "", unsupported("Tipe segmen nomor belum tercakup.")
			}
		}
		number := builder.String()
		existing, e := q.row("select id from number_sequence_issues where sequence_id=$1 and scope_key=$2 and formatted_value=$3 limit 1", s["id"], scope, number)
		if e != nil && !errors.Is(e, pgx.ErrNoRows) {
			return "", e
		}
		if existing != nil {
			continue
		}
		if e = q.insert("number_sequence_issues", object{"id": lowerUID(), "sequence_id": s["id"], "app_id": "management-aset", "scope_key": scope, "period_key": period, "numeric_value": n, "formatted_value": number, "idempotency_key": key, "is_manual": false, "issued_at": time.Now().UTC()}); e != nil {
			return "", e
		}
		if e = q.numberAudit(s["id"], "management-aset", "issued", number, object{"scope": scope}); e != nil {
			return "", e
		}
		return number, nil
	}
}
func (q *request) nextNumber(s object, scope, period string) (int64, error) {
	if !boolean(s["preallocation_enabled"]) {
		r, e := q.row("select numeric_value from number_sequence_reusable_numbers where sequence_id=$1 and scope_key=$2 and period_key=$3 order by numeric_value for update limit 1", s["id"], scope, period)
		if e == nil {
			n := integer(r["numeric_value"])
			e = q.exec("delete from number_sequence_reusable_numbers where sequence_id=$1 and scope_key=$2 and period_key=$3 and numeric_value=$4", s["id"], scope, period, n)
			return n, e
		}
		if !errors.Is(e, pgx.ErrNoRows) {
			return 0, e
		}
	}
	c, e := q.row("select * from number_sequence_counters where sequence_id=$1 and scope_key=$2 and period_key=$3 for update", s["id"], scope, period)
	if errors.Is(e, pgx.ErrNoRows) {
		e = q.exec("insert into number_sequence_counters(id,sequence_id,scope_key,period_key,next_number,created_at,updated_at) values($1,$2,$3,$4,$5,now(),now()) on conflict do nothing", uid(), s["id"], scope, period, s["minimum_number"])
		if e != nil {
			return 0, e
		}
		c, e = q.row("select * from number_sequence_counters where sequence_id=$1 and scope_key=$2 and period_key=$3 for update", s["id"], scope, period)
	}
	if e != nil {
		return 0, e
	}
	n := integer(c["next_number"])
	if boolean(s["preallocation_enabled"]) {
		a, e := q.row("select * from number_sequence_allocations where sequence_id=$1 and scope_key=$2 and period_key=$3 and next_number<=last_number order by created_at for update limit 1", s["id"], scope, period)
		if errors.Is(e, pgx.ErrNoRows) {
			if e = q.exec("delete from number_sequence_allocations where sequence_id=$1 and scope_key=$2 and period_key=$3 and next_number>last_number", s["id"], scope, period); e != nil {
				return 0, e
			}
			if s["maximum_number"] != nil && n > integer(s["maximum_number"]) {
				return 0, invalid("reference", "Rentang nomor sudah habis.")
			}
			last := n + integer(s["preallocation_quantity"]) - 1
			if s["maximum_number"] != nil && last > integer(s["maximum_number"]) {
				last = integer(s["maximum_number"])
			}
			a = object{"id": lowerUID(), "sequence_id": s["id"], "scope_key": scope, "period_key": period, "first_number": n, "last_number": last, "next_number": n}
			if e = q.insert("number_sequence_allocations", a); e != nil {
				return 0, e
			}
			if e = q.exec("update number_sequence_counters set next_number=$2,updated_at=now() where id=$1", c["id"], last+1); e != nil {
				return 0, e
			}
			if e = q.numberAudit(s["id"], nil, "preallocated", nil, object{"scope": scope, "first": n, "last": last}); e != nil {
				return 0, e
			}
		} else if e != nil {
			return 0, e
		}
		n = integer(a["next_number"])
		if s["maximum_number"] != nil && n > integer(s["maximum_number"]) {
			return 0, invalid("reference", "Rentang nomor sudah habis.")
		}
		return n, q.exec("update number_sequence_allocations set next_number=$2,updated_at=now() where id=$1", a["id"], n+1)
	}
	if s["maximum_number"] != nil && n > integer(s["maximum_number"]) {
		return 0, invalid("reference", "Rentang nomor sudah habis.")
	}
	return n, q.exec("update number_sequence_counters set next_number=$2,updated_at=now() where id=$1", c["id"], n+1)
}
func (q *request) numberAudit(sequence, app any, event string, number any, details object) error {
	return q.insert("number_sequence_audit_events", object{"id": uid(), "sequence_id": sequence, "app_id": app, "user_id": nil, "event_type": event, "formatted_value": number, "details": jsonText(details), "occurred_at": time.Now().UTC()})
}

func (q *request) assetNumberValues(s object, group, location, date string) (object, error) {
	values := object{}
	fields := map[string]bool{}
	for _, seg := range objects(s["segments"]) {
		if str(seg["type"]) == "context" {
			fields[str(seg["value"])] = true
		}
	}
	if len(fields) == 0 {
		return values, nil
	}
	g, e := q.row("select * from aset_m_group_aset where tenant_id=$1 and id=$2 and deleted_at is null for share", q.authority.tenant, group)
	if e != nil {
		return nil, e
	}
	c, e := q.row("select * from aset_m_kelompok_harta_fiskal where tenant_id=$1 and id=$2 and deleted_at is null for share", q.authority.tenant, g["kelompok_harta_fiskal_id"])
	if e != nil && !errors.Is(e, pgx.ErrNoRows) {
		return nil, e
	}
	if location == "" {
		location = str(g["lokasi_aset_id"])
	}
	l, e := q.row("select * from aset_m_lokasi_aset where tenant_id=$1 and id=$2 and deleted_at is null for share", q.authority.tenant, location)
	if e != nil && !errors.Is(e, pgx.ErrNoRows) {
		return nil, e
	}
	all := object{"asset_classification": c["kode"], "asset_group": g["kode"], "asset_location": l["kode"], "acquisition_year": date[:4]}
	for k := range fields {
		values[k] = all[k]
	}
	return values, nil
}
