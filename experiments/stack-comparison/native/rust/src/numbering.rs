use crate::db::{Cached, Client};
use crate::{
    auth::Context,
    db::{self, Error, Result, flag, nullable, number, text},
};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;

pub async fn asset_context(
    db: &Client,
    ctx: &Context,
    group: &str,
    location: Option<String>,
    date: &str,
) -> Result<BTreeMap<String, String>> {
    let sequence=db::one(db,"select s.* from tenant_number_sequences s where s.tenant_id=$1 and s.status='active' and exists (select 1 from app_number_sequence_references r where r.id=s.reference_id and r.app_id='management-aset' and r.code='management-aset.asset-code') for share",&[&ctx.tenant_id]).await?;
    let fields: Vec<String> = sequence["segments"]
        .as_array()
        .unwrap_or(&vec![])
        .iter()
        .filter(|segment| text(segment, "type") == "context")
        .map(|segment| text(segment, "value"))
        .collect();
    let mut values = BTreeMap::new();
    if fields.is_empty() {
        return Ok(values);
    }
    let group=db::one(db,"select * from aset_m_group_aset where tenant_id=$1 and id=$2 and deleted_at is null for share",&[&ctx.tenant_id,&group]).await?;
    let classification = nullable(&group, "kelompok_harta_fiskal_id");
    let classification=db::optional(db,"select kode from aset_m_kelompok_harta_fiskal where tenant_id=$1 and id=$2 and deleted_at is null for share",&[&ctx.tenant_id,&classification]).await?;
    let location = location.or_else(|| nullable(&group, "lokasi_aset_id"));
    let location=db::optional(db,"select kode from aset_m_lokasi_aset where tenant_id=$1 and id=$2 and deleted_at is null for share",&[&ctx.tenant_id,&location]).await?;
    for field in fields {
        let value = match field.as_str() {
            "asset_group" => text(&group, "kode"),
            "asset_classification" => classification
                .as_ref()
                .map(|row| text(row, "kode"))
                .unwrap_or_default(),
            "asset_location" => location
                .as_ref()
                .map(|row| text(row, "kode"))
                .unwrap_or_default(),
            "acquisition_year" => date.get(..4).unwrap_or_default().to_owned(),
            _ => {
                return Err(Error::unsupported(
                    "Bagian konteks nomor belum dicakup kandidat ini.",
                ));
            }
        };
        values.insert(field, value);
    }
    Ok(values)
}

pub async fn issue(
    db: &Client,
    ctx: &Context,
    company: &str,
    reference: &str,
    key: &str,
    values: &BTreeMap<String, String>,
    outer: bool,
) -> Result<String> {
    for attempt in 0..3 {
        db.batch_execute(if outer {
            "savepoint number_issue"
        } else {
            "begin"
        })
        .await?;
        let result = issue_inner(db, ctx, company, reference, key, values).await;
        match result {
            Ok(number) => {
                db.batch_execute(if outer {
                    "release savepoint number_issue"
                } else {
                    "commit"
                })
                .await?;
                return Ok(number);
            }
            Err(error) => {
                db.batch_execute(if outer {
                    "rollback to savepoint number_issue;release savepoint number_issue"
                } else {
                    "rollback"
                })
                .await?;
                if error.sql_state.as_deref() != Some("23505") || attempt == 2 {
                    return Err(error);
                }
                if let Some(row)=db::optional(db,"select i.formatted_value from number_sequence_issues i join tenant_number_sequences s on s.id=i.sequence_id join app_number_sequence_references r on r.id=s.reference_id where s.tenant_id=$1 and r.code=$2 and r.app_id='management-aset' and i.app_id='management-aset' and i.idempotency_key=$3",&[&ctx.tenant_id,&reference,&key]).await?{return Ok(text(&row,"formatted_value"));}
            }
        }
    }
    unreachable!()
}

async fn issue_inner(
    db: &Client,
    ctx: &Context,
    company: &str,
    reference: &str,
    key: &str,
    values: &BTreeMap<String, String>,
) -> Result<String> {
    let sequence=db::optional(db,"select s.* from tenant_number_sequences s where s.tenant_id=$1 and s.status='active' and exists(select 1 from app_number_sequence_references r where r.id=s.reference_id and r.app_id='management-aset' and r.code=$2) for share",&[&ctx.tenant_id,&reference]).await?.ok_or_else(||Error::coded(422,"number_sequence_failed","Sequence aktif tidak ditemukan untuk aplikasi dan tenant ini."))?;
    if flag(&sequence, "is_continuous") {
        return Err(Error::coded(
            422,
            "number_sequence_failed",
            "Sequence berkelanjutan harus memakai reserve lalu confirm.",
        ));
    }
    if !flag(&sequence, "allow_automatic") {
        return Err(Error::coded(
            422,
            "number_sequence_failed",
            "Pembuatan nomor otomatis tidak diizinkan.",
        ));
    }
    let sequence_id = text(&sequence, "id");
    let scope_type = text(&sequence, "scope_type");
    let mut scope_code = String::new();
    let mut scope = match scope_type.as_str() {
        "tenant" => format!("tenant:{}", ctx.tenant_id),
        "legal_entity" | "operating_unit" => {
            let organization = if scope_type == "legal_entity" {
                company.to_owned()
            } else {
                ctx.org_id.clone().unwrap_or_default()
            };
            let row=db::optional(db,"select o.*,le.company_code from organizations o left join legal_entities le on le.organization_id=o.id where o.tenant_id=$1 and o.id=$2 and o.classification=$3 and o.status='active'",&[&ctx.tenant_id,&organization,&scope_type]).await?.ok_or_else(||Error::coded(422,"number_sequence_failed","Context organisasi tidak sah untuk sequence ini."))?;
            scope_code = if scope_type == "legal_entity" {
                text(&row, "company_code")
            } else {
                organization.clone()
            };
            format!("{scope_type}:{organization}")
        }
        _ => {
            return Err(Error::unsupported(
                "Scope nomor belum dicakup kandidat ini.",
            ));
        }
    };
    let segments = sequence["segments"]
        .as_array()
        .ok_or_else(|| Error::coded(422, "number_sequence_failed", "Format nomor tidak sah."))?;
    let mut used_values = BTreeMap::new();
    for segment in segments
        .iter()
        .filter(|segment| text(segment, "type") == "context")
    {
        let field = text(segment, "value");
        let value = values.get(&field).cloned().unwrap_or_default();
        if value.is_empty()
            || value.len() > number(segment, "length") as usize
            || !value
                .bytes()
                .all(|byte| byte.is_ascii_alphanumeric() || byte == b'_' || byte == b'-')
        {
            return Err(Error::coded(
                422,
                "number_sequence_failed",
                "Lengkapi kode untuk bagian nomor sebelum menerbitkan nomor.",
            ));
        }
        used_values.insert(field, value);
    }
    if !used_values.is_empty() {
        scope.push_str(&format!(
            "|values:{}",
            hex::encode(Sha256::digest(serde_json::to_vec(&used_values).unwrap()))
        ));
    }
    let year = chrono::Utc::now().format("%Y").to_string();
    let period = match text(&sequence, "reset_period").as_str() {
        "never" => "all".to_owned(),
        "calendar_year" => year.clone(),
        _ => {
            return Err(Error::unsupported(
                "Reset nomor fiskal belum dicakup kandidat ini.",
            ));
        }
    };
    if let Some(existing)=db::optional(db,"select * from number_sequence_issues where sequence_id=$1 and app_id='management-aset' and idempotency_key=$2 limit 1",&[&sequence_id,&key]).await?{return Ok(text(&existing,"formatted_value"));}
    loop {
        let numeric = next_number(db, &sequence, &scope, &period).await?;
        let mut formatted = String::new();
        for segment in segments {
            formatted.push_str(&match text(segment, "type").as_str() {
                "constant" => text(segment, "value"),
                "scope" => scope_code.clone(),
                "year" => year.clone(),
                "context" => used_values
                    .get(&text(segment, "value"))
                    .cloned()
                    .unwrap_or_default(),
                "number" => {
                    let width = number(segment, "length") as usize;
                    if numeric.to_string().len() > width {
                        return Err(Error::coded(
                            422,
                            "number_sequence_failed",
                            "Nomor sudah melampaui panjang segmen yang dikonfigurasi.",
                        ));
                    }
                    format!("{numeric:0width$}")
                }
                _ => {
                    return Err(Error::unsupported(
                        "Segmen nomor belum dicakup kandidat ini.",
                    ));
                }
            });
        }
        if reference == "management-aset.asset-code" && formatted.len() > 50 {
            return Err(Error::coded(
                422,
                "number_sequence_failed",
                "Format kode aset melebihi 50 karakter.",
            ));
        }
        if db::optional(db,"select id from number_sequence_issues where sequence_id=$1 and scope_key=$2 and formatted_value=$3 limit 1",&[&sequence_id,&scope,&formatted]).await?.is_some(){continue;}
        db::insert(db,"number_sequence_issues",json!({"id":db::id().to_uppercase(),"sequence_id":sequence_id,"app_id":"management-aset","scope_key":scope,"period_key":period,"numeric_value":numeric,"formatted_value":formatted,"idempotency_key":key,"is_manual":false,"issued_at":db::now()}),true).await?;
        audit(
            db,
            &sequence_id,
            Some("management-aset"),
            "issued",
            Some(&formatted),
            json!({"scope":scope}),
        )
        .await?;
        return Ok(formatted);
    }
}

async fn next_number(db: &Client, sequence: &Value, scope: &str, period: &str) -> Result<i64> {
    let sequence_id = text(sequence, "id");
    if !flag(sequence, "preallocation_enabled") {
        if let Some(row)=db::optional(db,"select numeric_value from number_sequence_reusable_numbers where sequence_id=$1 and scope_key=$2 and period_key=$3 order by numeric_value for update limit 1",&[&sequence_id,&scope,&period]).await?{
            let numeric=number(&row,"numeric_value");
            db.cached_execute("delete from number_sequence_reusable_numbers where sequence_id=$1 and scope_key=$2 and period_key=$3 and numeric_value=$4",&[&sequence_id,&scope,&period,&numeric]).await?;
            return Ok(numeric);
        }
    }
    let mut counter=db::optional(db,"select * from number_sequence_counters where sequence_id=$1 and scope_key=$2 and period_key=$3 for update",&[&sequence_id,&scope,&period]).await?;
    if counter.is_none() {
        let id = db::id().to_uppercase();
        let minimum = number(sequence, "minimum_number");
        db.cached_execute("insert into number_sequence_counters(id,sequence_id,scope_key,period_key,next_number,created_at,updated_at) values($1,$2,$3,$4,$5,now(),now()) on conflict do nothing",&[&id,&sequence_id,&scope,&period,&minimum]).await?;
        counter=Some(db::one(db,"select * from number_sequence_counters where sequence_id=$1 and scope_key=$2 and period_key=$3 for update",&[&sequence_id,&scope,&period]).await?);
    }
    let counter = counter.unwrap();
    let counter_id = text(&counter, "id");
    let next = number(&counter, "next_number");
    if !flag(sequence, "preallocation_enabled") {
        check_max(sequence, next)?;
        db.cached_execute(
            "update number_sequence_counters set next_number=$2,updated_at=now() where id=$1",
            &[&counter_id, &(next + 1)],
        )
        .await?;
        return Ok(next);
    }
    let mut allocation=db::optional(db,"select * from number_sequence_allocations where sequence_id=$1 and scope_key=$2 and period_key=$3 and next_number<=last_number order by created_at for update limit 1",&[&sequence_id,&scope,&period]).await?;
    if allocation.is_none() {
        db.cached_execute("delete from number_sequence_allocations where sequence_id=$1 and scope_key=$2 and period_key=$3 and next_number>last_number",&[&sequence_id,&scope,&period]).await?;
        check_max(sequence, next)?;
        let mut last = next + number(sequence, "preallocation_quantity") - 1;
        if !sequence["maximum_number"].is_null() {
            last = last.min(number(sequence, "maximum_number"));
        }
        allocation=Some(db::insert(db,"number_sequence_allocations",json!({"id":db::id(),"sequence_id":sequence_id,"scope_key":scope,"period_key":period,"first_number":next,"last_number":last,"next_number":next}),true).await?);
        db.cached_execute(
            "update number_sequence_counters set next_number=$2,updated_at=now() where id=$1",
            &[&counter_id, &(last + 1)],
        )
        .await?;
        audit(
            db,
            &sequence_id,
            None,
            "preallocated",
            None,
            json!({"scope":scope,"first":next,"last":last}),
        )
        .await?;
    }
    let allocation = allocation.unwrap();
    let numeric = number(&allocation, "next_number");
    check_max(sequence, numeric)?;
    db.cached_execute(
        "update number_sequence_allocations set next_number=$2,updated_at=now() where id=$1",
        &[&text(&allocation, "id"), &(numeric + 1)],
    )
    .await?;
    Ok(numeric)
}
fn check_max(sequence: &Value, number_value: i64) -> Result<()> {
    if !sequence["maximum_number"].is_null() && number_value > number(sequence, "maximum_number") {
        Err(Error::coded(
            422,
            "number_sequence_failed",
            "Nomor sequence sudah mencapai batas maksimum.",
        ))
    } else {
        Ok(())
    }
}
async fn audit(
    db: &Client,
    sequence: &str,
    app: Option<&str>,
    event: &str,
    formatted: Option<&str>,
    details: Value,
) -> Result<()> {
    db::insert(db,"number_sequence_audit_events",json!({"id":db::id().to_uppercase(),"sequence_id":sequence,"app_id":app,"user_id":null,"event_type":event,"formatted_value":formatted,"details":details,"occurred_at":db::now()}),true).await?;
    Ok(())
}
