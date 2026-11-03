use crate::db::{Cached, Client};
use crate::{
    auth::Context,
    db::{self, Error, Result},
};
use base64::{
    Engine,
    engine::general_purpose::{STANDARD, URL_SAFE_NO_PAD},
};
use regex::Regex;
use serde_json::{Value, json};
use std::collections::HashMap;
use unicode_normalization::UnicodeNormalization;

pub const RECEIPT_SELECT: &str = r#"select r.*,
    (select count(*) from aset_tr_penerimaan_aset_details d where d.penerimaan_aset_id=r.id and d.tenant_id=r.tenant_id) as jumlah_baris,
    (select coalesce(sum(d.jumlah),0) from aset_tr_penerimaan_aset_details d where d.penerimaan_aset_id=r.id and d.tenant_id=r.tenant_id) as jumlah_aset,
    l.kode as lokasi_aset_kode,l.nama as lokasi_aset_nama,
    (select name from organizations o where o.tenant_id=r.tenant_id and o.id=r.legal_entity_id) as legal_entity_nama,
    (select name from organizations o where o.tenant_id=r.tenant_id and o.id=r.responsible_org_unit_id) as responsible_org_unit_nama,
    (select name from organizations o where o.tenant_id=r.tenant_id and o.id=r.receiving_org_unit_id) as receiving_org_unit_nama,
    (select u.name from tenant_memberships m join users u on u.id=m.user_id where m.tenant_id=r.tenant_id and m.user_id::text=r.diterima_oleh_user_id limit 1) as diterima_oleh_nama,
    (select u.name from tenant_memberships m join users u on u.id=m.user_id where m.tenant_id=r.tenant_id and m.user_id::text=r.penanggung_jawab_user_id limit 1) as penanggung_jawab_nama
    from aset_tr_penerimaan_aset r left join aset_m_lokasi_aset l on l.id=r.lokasi_aset_id and l.tenant_id=r.tenant_id
    where r.tenant_id=$1 and r.legal_entity_id=$2 and r.deleted_at is null"#;

pub fn scope(alias: &str) -> String {
    format!(
        " and exists(select 1 from jsonb_array_elements($3::jsonb) grant_data where (grant_data->>'legal_entity_id' is null and grant_data->>'organization_id' is null) or (grant_data->>'legal_entity_id'={alias}.legal_entity_id::text and grant_data->'operating_unit_ids' ? {alias}.responsible_org_unit_id::text))"
    )
}
pub fn policy(ctx: &Context) -> Value {
    json!(
        ctx.policies
            .get("management-aset.asset-responsibility")
            .cloned()
            .unwrap_or_default()
    )
}

pub async fn receipt_list(
    db: &Client,
    ctx: &Context,
    query: &HashMap<String, String>,
) -> Result<Value> {
    ctx.require("management-aset.penerimaan-aset.read")?;
    if let Some(status) = query.get("status").filter(|value| !value.is_empty()) {
        if !["draft", "selesai", "cancelled"].contains(&status.as_str()) {
            return Err(Error::validation(
                "status",
                "The selected status is invalid.",
            ));
        }
    }
    for key in ["dari", "sampai"] {
        if let Some(value) = query.get(key).filter(|v| !v.is_empty()) {
            validate_date(key, value)?;
        }
    }
    let sql = format!(
        "{}{} and ($4::jsonb->>'status' is null or r.status=$4::jsonb->>'status') and ($4::jsonb->>'dari' is null or r.tanggal>=($4::jsonb->>'dari')::date) and ($4::jsonb->>'sampai' is null or r.tanggal<=($4::jsonb->>'sampai')::date)",
        RECEIPT_SELECT,
        scope("r")
    );
    let sorts = [
        ("id", "id"),
        ("legal_entity", "legal_entity_nama"),
        ("kode", "kode"),
        ("tanggal", "tanggal"),
        ("siap", "tanggal_siap_pakai"),
        ("unit", "responsible_org_unit_nama"),
        ("lokasi", "lokasi_aset_nama"),
        ("baris", "jumlah_baris"),
        ("jumlah", "jumlah_aset"),
        ("status", "status"),
    ];
    paginate(
        db,
        ctx,
        query,
        &sql,
        &sorts,
        &[
            "kode",
            "legal_entity_nama",
            "keterangan",
            "lokasi_aset_nama",
            "responsible_org_unit_nama",
            "status",
        ],
        "tanggal",
        &["jumlah_baris", "jumlah_aset"],
    )
    .await
}

pub async fn asset_list(
    db: &Client,
    ctx: &Context,
    query: &HashMap<String, String>,
) -> Result<Value> {
    ctx.require("management-aset.aset.read")?;
    if query.contains_key("planning_company_id") {
        return Err(Error::unsupported(
            "Lookup perencanaan belum dicakup kandidat ini.",
        ));
    }
    let sql = format!(
        "select a.*,o.name as legal_entity_nama,g.nama as group_nama,t.nama as type_nama,l.nama as location_nama from aset_tr_aset a left join organizations o on o.id=a.legal_entity_id and o.tenant_id=a.tenant_id left join aset_m_group_aset g on g.id=a.group_aset_id and g.tenant_id=a.tenant_id left join aset_m_jenis_aset t on t.id=a.jenis_aset_id and t.tenant_id=a.tenant_id left join aset_m_lokasi_aset l on l.id=a.lokasi_aset_id and l.tenant_id=a.tenant_id where a.tenant_id=$1 and a.legal_entity_id=$2 and a.deleted_at is null {} and ($4::jsonb->>'lifecycle_state' is null or a.lifecycle_state=$4::jsonb->>'lifecycle_state') and ($4::jsonb->>'exclude_state' is null or a.lifecycle_state<>$4::jsonb->>'exclude_state')",
        scope("a")
    );
    let sorts = [
        ("id", "id"),
        ("kode", "kode"),
        ("nama", "nama"),
        ("serial", "serial_number"),
        ("legal_entity", "legal_entity_nama"),
        ("group", "group_nama"),
        ("jenis", "type_nama"),
        ("lokasi", "location_nama"),
        ("nilai", "acquisition_value"),
        ("status", "lifecycle_state"),
        ("created_at", "created_at"),
    ];
    let mut response = paginate(
        db,
        ctx,
        query,
        &sql,
        &sorts,
        &["kode", "nama", "serial_number"],
        "created_at",
        &["acquisition_value"],
    )
    .await?;
    for row in response["data"].as_array_mut().unwrap() {
        *row = present_asset(row, true);
    }
    Ok(response)
}

async fn paginate(
    db: &Client,
    ctx: &Context,
    query: &HashMap<String, String>,
    source: &str,
    sorts: &[(&str, &str)],
    search_columns: &[&str],
    default_sort: &str,
    numeric: &[&str],
) -> Result<Value> {
    let page = query
        .get("per_page")
        .map(|v| v.parse::<usize>())
        .transpose()
        .map_err(|_| Error::validation("per_page", "The per page field must be an integer."))?
        .unwrap_or(40);
    if !(1..=100).contains(&page) {
        return Err(Error::validation(
            "per_page",
            "The per page field must be between 1 and 100.",
        ));
    }
    let sort = query
        .get("sort")
        .map(String::as_str)
        .unwrap_or(default_sort);
    let column = sorts
        .iter()
        .find(|(key, _)| *key == sort)
        .map(|(_, column)| *column)
        .ok_or_else(|| Error::validation("sort", "The selected sort is invalid."))?;
    let direction =
        query
            .get("direction")
            .map(String::as_str)
            .unwrap_or(if query.contains_key("sort") {
                "asc"
            } else {
                "desc"
            });
    if !["asc", "desc"].contains(&direction) {
        return Err(Error::validation(
            "direction",
            "The selected direction is invalid.",
        ));
    }
    let terms = search_terms(query.get("q").map(String::as_str).unwrap_or(""))?;
    let cursor = decode_cursor(
        query.get("cursor"),
        column == "id",
        numeric.contains(&column),
    )?;
    let forward = cursor["_pointsToNextItems"].as_bool().unwrap_or(true);
    let effective_direction = if forward {
        direction
    } else if direction == "asc" {
        "desc"
    } else {
        "asc"
    };
    let null_direction = if forward { "asc" } else { "desc" };
    let value_compare = if effective_direction == "asc" {
        ">"
    } else {
        "<"
    };
    let null_compare = if forward { ">" } else { "<" };
    let value_expression = if numeric.contains(&column) {
        format!("coalesce(\"{column}\",0)")
    } else {
        format!("coalesce(cast(\"{column}\" as text),'')")
    };
    let cursor_cast = if numeric.contains(&column) {
        "numeric"
    } else {
        "text"
    };
    let search=search_columns.iter().map(|column|format!("to_tsvector('simple'::regconfig,regexp_replace(normalize(coalesce(cast(\"{column}\" as text),''),NFC),'[^[:alnum:]]+',' ','g')) @@ (case when (term->>'phrase')::boolean then phraseto_tsquery('simple'::regconfig,term->>'query') else to_tsquery('simple'::regconfig,term->>'query') end)")).collect::<Vec<_>>().join(" or ");
    let identity_compare = if column == "id" {
        String::new()
    } else {
        format!(
            " or (list_sort_value=($6::jsonb->>'list_sort_value')::{cursor_cast} and id::text{value_compare}($6::jsonb->>'id'))"
        )
    };
    let identity_order = if column == "id" {
        String::new()
    } else {
        format!(",id {effective_direction}")
    };
    let sql = format!(
        "select * from (select list_records.*,case when \"{column}\" is null then 1 else 0 end as list_sort_null,{value_expression} as list_sort_value from ({source}) list_records where ($4::jsonb->>'record_id' is null or id::text=$4::jsonb->>'record_id') and (not($4::jsonb ? 'record_ids') or id::text in (select jsonb_array_elements_text($4::jsonb->'record_ids'))) and not exists(select 1 from jsonb_array_elements($5::jsonb) term where not({search}))) sorted_records where $6::jsonb='null'::jsonb or list_sort_null{null_compare}($6::jsonb->>'list_sort_null')::integer or (list_sort_null=($6::jsonb->>'list_sort_null')::integer and (list_sort_value{value_compare}($6::jsonb->>'list_sort_value')::{cursor_cast}{identity_compare})) order by list_sort_null {null_direction},list_sort_value {effective_direction}{identity_order} limit {}",
        page + 1
    );
    let mut filters = json!({});
    for key in [
        "status",
        "dari",
        "sampai",
        "lifecycle_state",
        "exclude_state",
        "record_id",
    ] {
        if let Some(value) = query.get(key).filter(|v| !v.is_empty()) {
            filters[key] = json!(value);
        }
    }
    if let Some(ids) = query.get("record_ids") {
        filters["record_ids"] = serde_json::from_str(ids).map_err(|_| {
            Error::validation("record_ids", "The record ids field must be an array.")
        })?;
    }
    let policies = policy(ctx);
    let mut rows = db::rows(
        db,
        &sql,
        &[
            &ctx.tenant_id,
            &ctx.company_id,
            &policies,
            &filters,
            &terms,
            &cursor,
        ],
    )
    .await?;
    let more = rows.len() > page;
    rows.truncate(page);
    if !forward {
        rows.reverse();
    }
    let next = if more || (!forward && !rows.is_empty()) {
        rows.last().map(|row|{
            let mut parameters=json!({"list_sort_null":row["list_sort_null"],"list_sort_value":row["list_sort_value"]});
            if column!="id"{parameters["id"]=row["id"].clone();}
            parameters["_pointsToNextItems"]=json!(true);
            URL_SAFE_NO_PAD.encode(serde_json::to_vec(&parameters).unwrap())
        })
    } else {
        None
    };
    for row in &mut rows {
        row.as_object_mut().unwrap().remove("list_sort_null");
        row.as_object_mut().unwrap().remove("list_sort_value");
        normalize_timestamps(row);
    }
    Ok(json!({"data":rows,"next_cursor":next}))
}

pub fn normalize_timestamps(row: &mut Value) {
    for field in ["created_at", "updated_at", "deleted_at"] {
        if let Some(value) = row[field].as_str() {
            row[field] = json!(value.replace('T', " "));
        }
    }
}

pub fn present_asset(row: &Value, lookups: bool) -> Value {
    let mut result = json!({});
    for field in [
        "id",
        "kode",
        "nama",
        "legal_entity_id",
        "responsible_org_unit_id",
        "group_aset_id",
        "kelompok_harta_fiskal_id",
        "jenis_aset_id",
        "kondisi_aset_id",
        "pabrikan_aset_id",
        "model_aset_id",
        "induk_aset_id",
        "lokasi_aset_id",
        "financial_dimension_org_unit_id",
        "serial_number",
        "model_number",
        "acquired_on",
        "placed_in_service_on",
        "acquisition_value",
        "currency_code",
        "lifecycle_state",
        "is_budgeted",
        "keterangan",
        "version",
    ] {
        result[field] = row[field].clone();
    }
    for field in ["acquired_on", "placed_in_service_on"] {
        if let Some(date) = row[field].as_str() {
            result[field] = json!(format!("{date}T00:00:00.000000Z"));
        }
    }
    result["acquisition_value"] = json!(crate::money::fixed(
        crate::money::decimal(&row["acquisition_value"]).unwrap_or_default(),
        2
    ));
    if lookups {
        for field in [
            "legal_entity_nama",
            "group_nama",
            "type_nama",
            "location_nama",
        ] {
            result[field] = row[field].clone();
        }
    }
    result
}

fn validate_date(field: &str, date: &str) -> Result<()> {
    if chrono::NaiveDate::parse_from_str(date, "%Y-%m-%d").is_err() || date.len() != 10 {
        Err(Error::validation(
            field,
            "The field must match the format Y-m-d.",
        ))
    } else {
        Ok(())
    }
}
fn decode_cursor(encoded: Option<&String>, identity: bool, numeric: bool) -> Result<Value> {
    let Some(encoded) = encoded.filter(|value| !value.is_empty()) else {
        return Ok(Value::Null);
    };
    let fail = || Error::validation("cursor", "Posisi daftar tidak valid. Muat ulang daftar.");
    if encoded.len() > 2000 {
        return Err(fail());
    }
    let decoded = URL_SAFE_NO_PAD
        .decode(encoded)
        .or_else(|_| STANDARD.decode(encoded))
        .map_err(|_| fail())?;
    let value: Value = serde_json::from_slice(&decoded).map_err(|_| fail())?;
    if ![Some(0), Some(1)].contains(&value["list_sort_null"].as_i64())
        || value["list_sort_value"].is_null()
        || (!identity && !value["id"].is_string())
        || value.as_object().is_none_or(|object| {
            object
                .values()
                .any(|value| value.is_array() || value.is_object() || value.is_null())
        })
    {
        return Err(fail());
    }
    if numeric && crate::money::decimal(&value["list_sort_value"]).is_err() {
        return Err(fail());
    }
    Ok(value)
}

fn search_terms(query: &str) -> Result<Value> {
    if query.chars().count() > 200 {
        return Err(Error::validation(
            "q",
            "Gunakan paling banyak 200 karakter untuk pencarian.",
        ));
    }
    let normalized: String = query.nfc().collect::<String>().to_lowercase();
    let whitespace = Regex::new(r"[\s\p{Z}\u{FEFF}]+").unwrap();
    let normalized = whitespace.replace_all(&normalized, " ");
    if normalized.trim().is_empty() {
        return Ok(json!([]));
    }
    let token = Regex::new(r#""([^"]*)"|([^\s"]+)"#).unwrap();
    let words = Regex::new(r"[\p{L}\p{N}]+").unwrap();
    let mut terms = Vec::new();
    for capture in token.captures_iter(&normalized) {
        let phrase = capture.get(1).is_some();
        let text = capture.get(1).or_else(|| capture.get(2)).unwrap().as_str();
        let words: Vec<&str> = words.find_iter(text).map(|word| word.as_str()).collect();
        if words.is_empty() {
            continue;
        }
        terms.push(json!({"phrase":phrase,"query":if phrase{words.join(" ")}else{words.into_iter().map(|word|format!("'{word}':*")).collect::<Vec<_>>().join(" & ")}}));
    }
    if terms.is_empty() {
        terms.push(json!({"phrase":false,"query":"'nativecomparisonimpossibleterm' & !'nativecomparisonimpossibleterm'"}));
    }
    Ok(json!(terms))
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn matches_record_search_prefix_phrase_and_punctuation_contract() {
        assert_eq!(
            search_terms("Ab-12 \"jenis aset\"").unwrap(),
            json!([{"phrase":false,"query":"'ab':* & '12':*"},{"phrase":true,"query":"jenis aset"}])
        );
        assert_eq!(search_terms("  ").unwrap(), json!([]));
        assert!(!search_terms("!!!").unwrap().as_array().unwrap().is_empty());
        assert!(search_terms(&"a".repeat(201)).is_err());
    }
    #[test]
    fn rejects_malformed_or_missing_cursor_fields() {
        assert!(decode_cursor(Some(&"broken".into()), false, false).is_err());
        let cursor = URL_SAFE_NO_PAD.encode(
            br#"{"list_sort_null":0,"list_sort_value":"x","id":"a","_pointsToNextItems":true}"#,
        );
        assert!(decode_cursor(Some(&cursor), false, false).is_ok());
    }
}
