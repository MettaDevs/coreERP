use crate::db::{Cached, Client};
use crate::{
    auth::Context,
    db::{self, Error, Result, flag, nullable, number, text},
    lists, money, numbering, posting,
};
use axum::{
    Json,
    http::{HeaderMap, HeaderValue, Method, StatusCode},
    response::{IntoResponse, Response},
};
use chrono::{Datelike, Duration, NaiveDate};
use rust_decimal::Decimal;
use serde_json::{Value, json};
use std::collections::{BTreeMap, HashMap, HashSet};

pub async fn route(
    db: &Client,
    ctx: &Context,
    method: &Method,
    path: &str,
    query: &HashMap<String, String>,
    headers: &HeaderMap,
    body: &Value,
) -> Result<Response> {
    if path == "/penerimaan-aset" {
        if *method == Method::GET {
            return Ok(Json(lists::receipt_list(db, ctx, query).await?).into_response());
        }
        if *method == Method::POST {
            return create(db, ctx, body, headers).await;
        }
    }
    if path == "/aset" && *method == Method::GET {
        return Ok(Json(lists::asset_list(db, ctx, query).await?).into_response());
    }
    if let Some(path) = path.strip_prefix("/penerimaan-aset/") {
        let mut segments = path.split('/');
        let id = segments.next().unwrap_or_default();
        let action = segments.next();
        if segments.next().is_some() {
            return Err(Error::new(404, "Not Found"));
        }
        return match (method, action) {
            (&Method::GET, None) => show_response(db, ctx, id).await,
            (&Method::PATCH, None) => replace(db, ctx, id, body, headers).await,
            (&Method::GET, Some("pratinjau-posting")) => preview(db, ctx, id).await,
            (&Method::POST, Some("selesaikan")) => complete(db, ctx, id, body, headers).await,
            (&Method::GET, Some("aset")) => generated(db, ctx, id).await,
            _ => Err(Error::new(404, "Not Found")),
        };
    }
    if let Some(id) = path.strip_prefix("/aset/").filter(|id| !id.contains('/')) {
        if *method == Method::GET {
            return asset_show(db, ctx, id).await;
        }
    }
    Err(Error::new(404, "Not Found"))
}

pub async fn header(db: &Client, ctx: &Context, id: &str) -> Result<Value> {
    let policies = lists::policy(ctx);
    let sql = format!("{}{} and r.id=$4", lists::RECEIPT_SELECT, lists::scope("r"));
    let mut row = db::one(db, &sql, &[&ctx.tenant_id, &ctx.company_id, &policies, &id]).await?;
    row.as_object_mut().unwrap().remove("legal_entity_nama");
    lists::normalize_timestamps(&mut row);
    Ok(row)
}

pub async fn lines(db: &Client, ctx: &Context, id: &str) -> Result<Vec<Value>> {
    let mut rows=db::rows(db,r#"select d.*,d.nilai_per_unit::text as nilai_per_unit,d.ppn_per_unit::text as ppn_per_unit,d.residu_per_unit::text as residu_per_unit,d.akumulasi_per_unit::text as akumulasi_per_unit,
        g.nama as group_aset_nama,t.nama as jenis_aset_nama,c.nama as kondisi_aset_nama,p.nama as pabrikan_aset_nama,m.nama as model_aset_nama,r.id as purchase_request_id,r.kode as purchase_request_code
        from aset_tr_penerimaan_aset_details d
        left join aset_tr_permintaan_pengadaan_aset_details rd on rd.id=d.permintaan_pembelian_detail_id and rd.tenant_id=d.tenant_id
        left join aset_tr_permintaan_pengadaan_aset r on r.id=rd.request_id and r.tenant_id=d.tenant_id
        left join aset_m_group_aset g on g.id=d.group_aset_id and g.tenant_id=d.tenant_id
        left join aset_m_jenis_aset t on t.id=d.jenis_aset_id and t.tenant_id=d.tenant_id
        left join aset_m_kondisi_aset c on c.id=d.kondisi_aset_id and c.tenant_id=d.tenant_id
        left join aset_m_pabrikan_aset p on p.id=d.pabrikan_aset_id and p.tenant_id=d.tenant_id
        left join aset_m_model_aset m on m.id=d.model_aset_id and m.tenant_id=d.tenant_id
        where d.tenant_id=$1 and d.penerimaan_aset_id=$2 order by d.line_number"#,&[&ctx.tenant_id,&id]).await?;
    for row in &mut rows {
        lists::normalize_timestamps(row);
    }
    Ok(rows)
}

async fn show(db: &Client, ctx: &Context, id: &str) -> Result<Value> {
    ctx.require("management-aset.penerimaan-aset.read")?;
    let mut row = header(db, ctx, id).await?;
    row["details"] = json!(lines(db, ctx, id).await?);
    row["vendor"]=match nullable(&row,"vendor_id"){
        Some(id)=>posting::vendor(db,&ctx.tenant_id,&id).await?.map(|vendor|json!({"id":vendor["id"],"number":vendor["number"],"name":vendor["name"],"status":vendor["status"]})).unwrap_or(Value::Null),None=>Value::Null,
    };
    row["posting"] = if ["selesai", "cancelled"].contains(&text(&row, "status").as_str()) {
        posting::status(db, &ctx.tenant_id, &format!("AST-ACQ-{id}")).await?
    } else {
        Value::Null
    };
    let cancellation=db::optional(db,"select * from aset_tr_pembatalan where tenant_id=$1 and resource='penerimaan-aset' and document_id=$2 and deleted_at is null order by created_at desc,id desc limit 1",&[&ctx.tenant_id,&id]).await?;
    row["cancellation"] = if let Some(cancellation) = cancellation {
        let mut postings = Vec::new();
        for posting_id in cancellation["posting_ids"].as_array().unwrap_or(&vec![]) {
            if let Some(id) = posting_id.as_str() {
                let status = posting::status(db, &ctx.tenant_id, id).await?;
                if !status.is_null() {
                    postings.push(status);
                }
            }
        }
        json!({"status":cancellation["status"],"reason":cancellation["reason"],"failure_message":cancellation["failure_message"],"posting_date":cancellation["posting_date"],"postings":postings})
    } else {
        Value::Null
    };
    Ok(row)
}
async fn show_response(db: &Client, ctx: &Context, id: &str) -> Result<Response> {
    let row = show(db, ctx, id).await?;
    Ok(version_response(row))
}
fn version_response(row: Value) -> Response {
    let version = number(&row, "version");
    let mut response = Json(json!({"data":row})).into_response();
    response.headers_mut().insert(
        "etag",
        HeaderValue::from_str(&format!("W/\"{version}\"")).unwrap(),
    );
    response
}

struct ReceiptInput {
    header: Value,
    lines: Vec<Value>,
}
async fn validate(db: &Client, ctx: &Context, input: &Value) -> Result<ReceiptInput> {
    let mut header = json!({});
    for field in [
        "legal_entity_id",
        "responsible_org_unit_id",
        "receiving_org_unit_id",
        "tanggal",
        "tanggal_siap_pakai",
        "diterima_oleh_user_id",
        "penanggung_jawab_user_id",
        "lokasi_aset_id",
        "currency_code",
        "keterangan",
        "cara_perolehan",
        "vendor_id",
        "vendor_invoice_reference",
        "purchase_order_reference",
        "vendor_invoice_date",
    ] {
        header[field] = input[field].clone();
    }
    for field in [
        "legal_entity_id",
        "responsible_org_unit_id",
        "receiving_org_unit_id",
        "lokasi_aset_id",
        "vendor_id",
    ] {
        if field == "legal_entity_id" || !header[field].is_null() {
            validate_ulid(field, &text(&header, field))?;
        }
    }
    validate_date("tanggal", &text(&header, "tanggal"))?;
    for field in ["tanggal_siap_pakai", "vendor_invoice_date"] {
        if let Some(date) = nullable(&header, field) {
            validate_date(field, &date)?;
        }
    }
    if !header["tanggal_siap_pakai"].is_null()
        && text(&header, "tanggal_siap_pakai") < text(&header, "tanggal")
    {
        return Err(Error::validation(
            "tanggal_siap_pakai",
            "Tanggal siap pakai tidak boleh sebelum tanggal penerimaan.",
        ));
    }
    let currency = text(&header, "currency_code").to_uppercase();
    if currency.len() != 3 {
        return Err(Error::validation(
            "currency_code",
            "The currency code field must be 3 characters.",
        ));
    }
    header["currency_code"] = json!(currency);
    for (field, max) in [
        ("keterangan", 2000),
        ("vendor_invoice_reference", 80),
        ("purchase_order_reference", 80),
        ("diterima_oleh_user_id", 64),
        ("penanggung_jawab_user_id", 64),
    ] {
        if !header[field].is_null()
            && (!header[field].is_string() || text(&header, field).chars().count() > max)
        {
            return Err(Error::validation(field, "Isian terlalu panjang."));
        }
    }
    header["cara_perolehan"] = if header["cara_perolehan"].is_null() {
        json!("pembelian")
    } else {
        header["cara_perolehan"].clone()
    };
    if text(&header, "cara_perolehan") != "pembelian" {
        return Err(Error::unsupported(
            "Hibah dan saldo awal belum dicakup kandidat ini.",
        ));
    }
    if let Some(location) = nullable(&header, "lokasi_aset_id") {
        exists_master(db, ctx, "aset_m_lokasi_aset", &location, "lokasi_aset_id").await?;
    }
    if header["responsible_org_unit_id"].is_null() {
        header["responsible_org_unit_id"] = json!(
            location_value(
                db,
                ctx,
                nullable(&header, "lokasi_aset_id"),
                "departemen_bawaan_id"
            )
            .await?
        );
    }
    if header["responsible_org_unit_id"].is_null() {
        return Err(Error::validation(
            "responsible_org_unit_id",
            "Pilih unit penanggung jawab, atau pilih lokasi yang punya unit kerja bawaan.",
        ));
    }
    ctx.require_scope(
        &text(&header, "legal_entity_id"),
        nullable(&header, "responsible_org_unit_id").as_deref(),
    )?;
    let reference = text(&header, "purchase_order_reference").trim().to_owned();
    header["purchase_order_reference"] = if reference.is_empty() {
        Value::Null
    } else {
        json!(reference)
    };
    let mut lines = match &input["details"] {
        Value::Array(values) => values.clone(),
        Value::Object(values) => values.values().cloned().collect(),
        _ => {
            return Err(Error::validation(
                "details",
                "The details field is required.",
            ));
        }
    };
    if lines.is_empty() {
        return Err(Error::validation(
            "details",
            "The details field must have at least 1 items.",
        ));
    }
    let precision = posting::precision(db, &ctx.tenant_id, &currency).await?;
    let unit_decimals = number(&precision, "unit_amount_decimals") as u32;
    for (index, line) in lines.iter_mut().enumerate() {
        if !line.is_object() {
            return Err(Error::validation("details", "Baris tidak sah."));
        }
        let field = |key: &str| format!("details.{index}.{key}");
        let name = text(line, "nama");
        if name.is_empty() || name.chars().count() > 150 {
            return Err(Error::validation(
                &field("nama"),
                "The nama field is required and must not be greater than 150 characters.",
            ));
        }
        for (key, table) in [
            ("group_aset_id", "aset_m_group_aset"),
            ("jenis_aset_id", "aset_m_jenis_aset"),
            ("kondisi_aset_id", "aset_m_kondisi_aset"),
            ("pabrikan_aset_id", "aset_m_pabrikan_aset"),
            ("model_aset_id", "aset_m_model_aset"),
        ] {
            if ["group_aset_id", "jenis_aset_id"].contains(&key) || !line[key].is_null() {
                let id = text(line, key);
                validate_ulid(&field(key), &id)?;
                exists_master(db, ctx, table, &id, &field(key)).await?;
            }
        }
        let quantity = line["jumlah"]
            .as_i64()
            .or_else(|| line["jumlah"].as_str().and_then(|value| value.parse().ok()))
            .unwrap_or(0);
        if !(1..=500).contains(&quantity) {
            return Err(Error::validation(
                &field("jumlah"),
                "Jumlah harus 1 sampai 500.",
            ));
        }
        line["jumlah"] = json!(quantity);
        for key in [
            "nilai_per_unit",
            "ppn_per_unit",
            "residu_per_unit",
            "akumulasi_per_unit",
        ] {
            if key == "nilai_per_unit" && line[key].is_null() {
                return Err(Error::validation(&field(key), "The field is required."));
            }
            let decimal = money::decimal(&line[key])?;
            if decimal < Decimal::ZERO {
                return Err(Error::validation(&field(key), "Nilai tidak boleh negatif."));
            }
            if ["nilai_per_unit", "ppn_per_unit"].contains(&key)
                && decimal.normalize().scale() > unit_decimals
            {
                return Err(Error::validation(
                    &field(key),
                    &format!(
                        "Paling banyak {unit_decimals} angka di belakang koma untuk {currency}."
                    ),
                ));
            }
            line[key] = json!(decimal.normalize().to_string());
        }
        if money::decimal(&line["akumulasi_per_unit"])? > Decimal::ZERO
            || number(line, "periode_berjalan") > 0
            || line["saldo_awal_buku"]
                .as_array()
                .is_some_and(|v| !v.is_empty())
        {
            return Err(Error::validation(
                &field("akumulasi_per_unit"),
                "Akumulasi dan periode berjalan hanya diisi untuk saldo awal.",
            ));
        }
        line["periode_berjalan"] = json!(0);
        line["saldo_awal_buku"] = Value::Null;
        if !line["permintaan_pembelian_detail_id"].is_null() {
            return Err(Error::unsupported(
                "Penerimaan dari permintaan pembelian belum dicakup kandidat ini.",
            ));
        }
        if !line["attachments"].is_null() || !input["attachments"].is_null() {
            return Err(Error::unsupported(
                "Lampiran unggahan belum dicakup kandidat ini.",
            ));
        }
        condition(db, ctx, line).await?;
        let attributes = match &line["atribut"] {
            Value::Null => vec![],
            Value::Array(values) => values.clone(),
            Value::Object(values) => values.values().cloned().collect(),
            _ => {
                return Err(Error::validation(
                    &field("atribut"),
                    "The atribut field must be an array.",
                ));
            }
        };
        line["atribut"] = json!(attributes);
        attribute_rows(db, ctx, &text(line, "jenis_aset_id"), &attributes)
            .await
            .map_err(|mut error| {
                if let Some(errors) = error.body["errors"].as_object() {
                    let mut prefixed = serde_json::Map::new();
                    for (key, value) in errors {
                        prefixed.insert(format!("details.{index}.{key}"), value.clone());
                    }
                    error.body["errors"] = Value::Object(prefixed);
                }
                error
            })?;
        if let Some(model) = nullable(line, "model_aset_id") {
            let model=db::one(db,"select pabrikan_aset_id,jenis_aset_id,aktif from aset_m_model_aset where tenant_id=$1 and id=$2 and deleted_at is null",&[&ctx.tenant_id,&model]).await?;
            if !flag(&model, "aktif") {
                return Err(Error::validation(
                    &field("model_aset_id"),
                    "Model yang dipilih sudah tidak aktif.",
                ));
            }
            if line["pabrikan_aset_id"] != model["pabrikan_aset_id"] {
                return Err(Error::validation(
                    &field("model_aset_id"),
                    "Model harus berasal dari pabrikan yang dipilih.",
                ));
            }
            let linked=db::optional(db,"select id from aset_m_model_aset where tenant_id=$1 and jenis_aset_id=$2 and aktif and deleted_at is null limit 1",&[&ctx.tenant_id,&text(line,"jenis_aset_id")]).await?.is_some();
            if (linked && model["jenis_aset_id"] != line["jenis_aset_id"])
                || (!linked && !model["jenis_aset_id"].is_null())
            {
                return Err(Error::validation(
                    &field("model_aset_id"),
                    "Model ini belum dikaitkan dengan jenis aset yang dipilih.",
                ));
            }
        }
    }
    if let Some(vendor_id) = nullable(&header, "vendor_id") {
        let vendor = posting::vendor(db, &ctx.tenant_id, &vendor_id).await?;
        if vendor
            .as_ref()
            .is_none_or(|vendor| vendor["legal_entity_id"] != header["legal_entity_id"])
        {
            return Err(Error::validation(
                "vendor_id",
                "Pilih vendor milik entitas legal penerimaan ini.",
            ));
        }
    }
    Ok(ReceiptInput { header, lines })
}

async fn exists_master(
    db: &Client,
    ctx: &Context,
    table: &str,
    id: &str,
    field: &str,
) -> Result<()> {
    if db::optional(
        db,
        &format!("select id from {table} where tenant_id=$1 and id=$2 and deleted_at is null"),
        &[&ctx.tenant_id, &id],
    )
    .await?
    .is_none()
    {
        return Err(Error::validation(
            field,
            &format!("The selected {field} is invalid."),
        ));
    }
    Ok(())
}
fn validate_ulid(field: &str, id: &str) -> Result<()> {
    if ulid::Ulid::from_string(id).is_err() {
        Err(Error::validation(field, "The field must be a valid ULID."))
    } else {
        Ok(())
    }
}
fn validate_date(field: &str, date: &str) -> Result<()> {
    if date.len() != 10 || NaiveDate::parse_from_str(date, "%Y-%m-%d").is_err() {
        Err(Error::validation(
            field,
            "The field must match the format Y-m-d.",
        ))
    } else {
        Ok(())
    }
}

async fn create(db: &Client, ctx: &Context, body: &Value, headers: &HeaderMap) -> Result<Response> {
    ctx.require("management-aset.penerimaan-aset.create")?;
    let key = headers
        .get("idempotency-key")
        .and_then(|value| value.to_str().ok())
        .unwrap_or("");
    if key.is_empty()
        || key.len() > 143
        || !key
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || b"._:-".contains(&byte))
    {
        return Err(Error::validation(
            "key",
            "The key field is required and must contain only letters, numbers, dots, underscores, colons, and dashes.",
        ));
    }
    if let Some(existing) = replay(db, ctx, key).await? {
        return Ok((
            StatusCode::OK,
            [("idempotent-replayed", "true")],
            Json(json!({"data":existing})),
        )
            .into_response());
    }
    let input = validate(db, ctx, body).await?;
    let code = numbering::issue(
        db,
        ctx,
        &text(&input.header, "legal_entity_id"),
        "management-aset.penerimaan-aset",
        &format!("penerimaan-aset:{key}"),
        &BTreeMap::new(),
        false,
    )
    .await?;
    let id = db::id().to_uppercase();
    let mut new_header = input.header;
    new_header["id"] = json!(id);
    new_header["tenant_id"] = json!(ctx.tenant_id);
    new_header["creation_key"] = json!(key);
    new_header["kode"] = json!(code);
    new_header["status"] = json!("draft");
    db.batch_execute("begin").await?;
    let persisted = async {
        db::insert(db, "aset_tr_penerimaan_aset", new_header, true).await?;
        persist_lines(db, ctx, &id, &input.lines).await?;
        db.batch_execute("commit").await?;
        Ok::<(), Error>(())
    }
    .await;
    if let Err(error) = persisted {
        db.batch_execute("rollback").await?;
        if let Some(existing) = replay(db, ctx, key).await? {
            return Ok((
                StatusCode::OK,
                [("idempotent-replayed", "true")],
                Json(json!({"data":existing})),
            )
                .into_response());
        }
        return Err(error);
    }
    let mut result = header(db, ctx, &id).await?;
    result["details"] = json!(lines(db, ctx, &id).await?);
    let mut response = (StatusCode::CREATED, Json(json!({"data":result}))).into_response();
    let host = headers
        .get("host")
        .and_then(|value| value.to_str().ok())
        .unwrap_or("127.0.0.1");
    response.headers_mut().insert(
        "location",
        HeaderValue::from_str(&format!(
            "http://{host}/api/modules/management-aset/v1/penerimaan-aset/{id}"
        ))
        .unwrap(),
    );
    Ok(response)
}
async fn replay(db: &Client, ctx: &Context, key: &str) -> Result<Option<Value>> {
    let mut result = db::optional(
        db,
        "select * from aset_tr_penerimaan_aset where tenant_id=$1 and creation_key=$2 limit 1",
        &[&ctx.tenant_id, &key],
    )
    .await?;
    if let Some(row) = &mut result {
        ctx.require_scope(
            &text(row, "legal_entity_id"),
            nullable(row, "responsible_org_unit_id").as_deref(),
        )?;
        lists::normalize_timestamps(row);
    }
    Ok(result)
}
async fn persist_lines(db: &Client, ctx: &Context, id: &str, lines: &[Value]) -> Result<()> {
    for (index, line) in lines.iter().enumerate() {
        let mut data = json!({"id":db::id(),"tenant_id":ctx.tenant_id,"penerimaan_aset_id":id,"line_number":index+1});
        for field in [
            "nama",
            "group_aset_id",
            "jenis_aset_id",
            "kondisi_aset_id",
            "pabrikan_aset_id",
            "model_aset_id",
            "model_number",
            "jumlah",
            "nilai_per_unit",
            "ppn_per_unit",
            "residu_per_unit",
            "akumulasi_per_unit",
            "periode_berjalan",
            "saldo_awal_buku",
            "permintaan_pembelian_detail_id",
            "atribut",
            "keterangan",
        ] {
            data[field] = line[field].clone();
        }
        db::insert(db, "aset_tr_penerimaan_aset_details", data, true).await?;
    }
    Ok(())
}
pub fn expected_version(headers: &HeaderMap, body: &Value) -> Result<i32> {
    let parsed = if let Some(header) = headers.get("if-match") {
        let text = header.to_str().unwrap_or("").trim();
        let text = text.strip_prefix("W/").unwrap_or(text);
        text.strip_prefix('"')
            .and_then(|v| v.strip_suffix('"'))
            .filter(|value| !value.is_empty() && value.bytes().all(|b| b.is_ascii_digit()))
            .and_then(|value| value.parse::<i32>().ok())
    } else {
        body["version"]
            .as_i64()
            .and_then(|value| i32::try_from(value).ok())
            .or_else(|| {
                body["version"]
                    .as_str()
                    .filter(|value| !value.is_empty() && value.bytes().all(|b| b.is_ascii_digit()))
                    .and_then(|value| value.parse().ok())
            })
    };
    parsed.ok_or_else(||Error::coded(428,"version_required","Versi data yang sedang diubah wajib dikirim: header If-Match berisi ETag, atau field version."))
}
async fn claim(db: &Client, ctx: &Context, id: &str, version: i32) -> Result<()> {
    let changed=db.cached_execute("update aset_tr_penerimaan_aset set version=$3 where tenant_id=$1 and id=$2 and deleted_at is null and version=$3",&[&ctx.tenant_id,&id,&version]).await?;
    if changed == 0 {
        return Err(Error::coded(
            409,
            "stale_version",
            "Perubahanmu belum disimpan karena data ini sudah diubah sejak kamu membukanya. Muat ulang untuk melihat data terbaru, lalu ulangi perubahanmu.",
        ));
    }
    Ok(())
}
async fn replace(
    db: &Client,
    ctx: &Context,
    id: &str,
    body: &Value,
    headers: &HeaderMap,
) -> Result<Response> {
    ctx.require("management-aset.penerimaan-aset.update")?;
    let current = header(db, ctx, id).await?;
    if text(&current, "status") != "draft" {
        return Err(Error::new(
            422,
            "Penerimaan yang sudah selesai tidak dapat diubah; asetnya sudah terdaftar dan bernomor.",
        ));
    }
    let input = validate(db, ctx, body).await?;
    let version = expected_version(headers, body)?;
    db.batch_execute("begin").await?;
    claim(db, ctx, id, version).await?;
    let fields = input
        .header
        .as_object()
        .unwrap()
        .keys()
        .map(|field| format!("\"{field}\"=x.\"{field}\""))
        .collect::<Vec<_>>()
        .join(",");
    let changed=db.cached_execute(&format!("update aset_tr_penerimaan_aset r set {fields},updated_at=now() from json_populate_record(null::aset_tr_penerimaan_aset,$3) x where r.tenant_id=$1 and r.id=$2 and r.status='draft'"),&[&ctx.tenant_id,&id,&input.header]).await?;
    if changed == 0 {
        return Err(Error::new(
            422,
            "Penerimaan yang sudah selesai tidak dapat diubah; asetnya sudah terdaftar dan bernomor.",
        ));
    }
    db.cached_execute(
        "delete from aset_tr_penerimaan_aset_details where tenant_id=$1 and penerimaan_aset_id=$2",
        &[&ctx.tenant_id, &id],
    )
    .await?;
    persist_lines(db, ctx, id, &input.lines).await?;
    db.batch_execute("commit").await?;
    show_response(db, ctx, id).await
}

async fn preview(db: &Client, ctx: &Context, id: &str) -> Result<Response> {
    ctx.require("management-aset.penerimaan-aset.read")?;
    let receipt = header(db, ctx, id).await?;
    if text(&receipt, "status") != "draft" {
        return Err(Error::new(
            422,
            "Penerimaan ini sudah diselesaikan; jurnalnya sudah terbit dan keadaannya ada di rincian dokumen.",
        ));
    }
    let lines = lines(db, ctx, id).await?;
    let result = posting::evaluate(db, ctx, &receipt, &lines, &[], false).await?;
    Ok(Json(json!({"data":result.preview})).into_response())
}

async fn complete(
    db: &Client,
    ctx: &Context,
    id: &str,
    body: &Value,
    headers: &HeaderMap,
) -> Result<Response> {
    ctx.require("management-aset.aset.create")?;
    let receipt = header(db, ctx, id).await?;
    if text(&receipt, "status") != "draft" {
        return Err(Error::new(422, "Penerimaan ini sudah diselesaikan."));
    }
    let version = expected_version(headers, body)?;
    let lines = lines(db, ctx, id).await?;
    if lines.is_empty() {
        return Err(Error::validation(
            "details",
            "Penerimaan tanpa baris barang tidak dapat diselesaikan.",
        ));
    }
    posting::blockers(db, ctx, &receipt, &lines).await?;
    let precision =
        posting::precision(db, &ctx.tenant_id, &text(&receipt, "currency_code")).await?;
    let decimals = number(&precision, "amount_decimals") as u32;
    let mut prepared = Vec::new();
    for line in &lines {
        let quantity = number(line, "jumlah");
        let values = money::split(
            money::extended(&line["nilai_per_unit"], quantity, decimals)?,
            quantity,
            decimals,
        )?;
        let taxes = money::split(
            money::extended(&line["ppn_per_unit"], quantity, decimals)?,
            quantity,
            decimals,
        )?;
        for unit in 0..quantity as usize {
            prepared.push(json!({"line":line,"key":format!("penerimaan:{id}:{}:{}",number(line,"line_number"),unit+1),"value":values[unit],"tax":taxes[unit]}));
        }
    }
    db.batch_execute("begin").await?;
    claim(db, ctx, id, version).await?;
    let changed=db.cached_execute("update aset_tr_penerimaan_aset set status='selesai',updated_at=now() where tenant_id=$1 and id=$2 and status='draft'",&[&ctx.tenant_id,&id]).await?;
    if changed == 0 {
        return Err(Error::new(422, "Penerimaan ini sudah diselesaikan."));
    }
    let company = text(&receipt, "legal_entity_id");
    let date = text(&receipt, "tanggal");
    let mut contexts: HashMap<String, BTreeMap<String, String>> = HashMap::new();
    let mut ranked = Vec::new();
    for (position, item) in prepared.iter().enumerate() {
        let group = text(&item["line"], "group_aset_id");
        let key = format!("{group}|{}|{date}", text(&receipt, "lokasi_aset_id"));
        let values = if let Some(values) = contexts.get(&key) {
            values.clone()
        } else {
            let values = numbering::asset_context(
                db,
                ctx,
                &group,
                nullable(&receipt, "lokasi_aset_id"),
                &date,
            )
            .await?;
            contexts.insert(key, values.clone());
            values
        };
        ranked.push((serde_json::to_string(&values).unwrap(), position, values));
    }
    ranked.sort_by(|left, right| (&left.0, left.1).cmp(&(&right.0, right.1)));
    let mut codes = HashMap::new();
    for (_, position, values) in ranked {
        let code = numbering::issue(
            db,
            ctx,
            &company,
            "management-aset.asset-code",
            &format!("aset:{}", text(&prepared[position], "key")),
            &values,
            true,
        )
        .await?;
        codes.insert(position, code);
    }
    let mut published_assets = Vec::new();
    for (index, item) in prepared.iter().enumerate() {
        let code = &codes[&index];
        create_asset(
            db,
            ctx,
            &receipt,
            &item["line"],
            &text(item, "key"),
            code,
            &text(item, "value"),
        )
        .await?;
        published_assets.push(json!({"asset_code":code,"group_aset_id":item["line"]["group_aset_id"],"acquisition_value":item["value"],"tax_amount":item["tax"]}));
    }
    ctx.require("management-aset.penerimaan-aset.read")?;
    let scoped = header(db, ctx, id).await?;
    ctx.assert_row(&scoped)?;
    let attachments=db::rows(db,"select * from document_attachments where tenant_id=$1 and record_type='aset_tr_penerimaan_aset' and record_id=$2 and deleted_at is null order by id for update",&[&ctx.tenant_id,&id]).await?;
    if !attachments.is_empty() {
        return Err(Error::unsupported(
            "Pewarisan lampiran belum dicakup kandidat ini.",
        ));
    }
    posting::evaluate(db, ctx, &receipt, &lines, &published_assets, true).await?;
    db.batch_execute("commit").await?;
    show_response(db, ctx, id).await
}

async fn generated(db: &Client, ctx: &Context, id: &str) -> Result<Response> {
    ctx.require("management-aset.penerimaan-aset.read")?;
    let receipt = header(db, ctx, id).await?;
    let assets=db::rows(db,"select id,kode,nama,serial_number,penerimaan_aset_detail_id from aset_tr_aset where tenant_id=$1 and penerimaan_aset_id=$2 and deleted_at is null and not is_budgeted order by kode",&[&ctx.tenant_id,&id]).await?;
    Ok(Json(json!({"data":assets,"version":receipt["version"]})).into_response())
}

async fn condition(db: &Client, ctx: &Context, line: &Value) -> Result<()> {
    if let Some(condition) = nullable(line, "kondisi_aset_id") {
        let kind = text(line, "jenis_aset_id");
        let linked=db::optional(db,"select id from aset_m_jenis_aset_kondisi where tenant_id=$1 and jenis_aset_id=$2 and deleted_at is null limit 1",&[&ctx.tenant_id,&kind]).await?.is_some();
        let eligible=db::optional(db,"select c.id from aset_m_kondisi_aset c where c.tenant_id=$1 and c.id=$2 and c.aktif and c.deleted_at is null and (not $4 or exists(select 1 from aset_m_jenis_aset_kondisi l where l.tenant_id=c.tenant_id and l.jenis_aset_id=$3 and l.kondisi_aset_id=c.id and l.deleted_at is null))",&[&ctx.tenant_id,&condition,&kind,&linked]).await?.is_some();
        if !eligible {
            return Err(Error::validation(
                "kondisi_aset_id",
                "Pilih kondisi aktif yang tersedia untuk jenis aset ini.",
            ));
        }
    }
    Ok(())
}

pub async fn location_value(
    db: &Client,
    ctx: &Context,
    mut location: Option<String>,
    column: &str,
) -> Result<Option<String>> {
    let mut visited = HashSet::new();
    for _ in 0..32 {
        let Some(id) = location.filter(|id| !id.is_empty()) else {
            return Ok(None);
        };
        if !visited.insert(id.clone()) {
            return Ok(None);
        }
        let Some(row) = db::optional(
            db,
            &format!(
                "select {column},parent_id from aset_m_lokasi_aset where tenant_id=$1 and id=$2"
            ),
            &[&ctx.tenant_id, &id],
        )
        .await?
        else {
            return Ok(None);
        };
        if let Some(value) = nullable(&row, column) {
            return Ok(Some(value));
        }
        location = nullable(&row, "parent_id");
    }
    Ok(None)
}

async fn attribute_rows(
    db: &Client,
    ctx: &Context,
    kind: &str,
    submitted: &[Value],
) -> Result<Vec<Value>> {
    let ids:Vec<String>=db::rows(db,"select tipe_atribut_id from aset_m_jenis_aset_atribut where tenant_id=$1 and jenis_aset_id=$2 and deleted_at is null order by tipe_atribut_id",&[&ctx.tenant_id,&kind]).await?.iter().map(|row|text(row,"tipe_atribut_id")).collect();
    if !ids.is_empty() {
        db::rows(db,"select id from aset_m_tipe_atribut where tenant_id=$1 and id=any($2) order by id for update",&[&ctx.tenant_id,&ids]).await?;
    }
    let definitions=db::rows(db,"select t.*,a.wajib,a.urutan from aset_m_jenis_aset_atribut a join aset_m_tipe_atribut t on t.id=a.tipe_atribut_id and t.tenant_id=a.tenant_id where a.tenant_id=$1 and a.jenis_aset_id=$2 and a.deleted_at is null and t.deleted_at is null and t.aktif order by a.urutan,t.nama",&[&ctx.tenant_id,&kind]).await?;
    let choices = if ids.is_empty() {
        vec![]
    } else {
        db::rows(db,"select id,tipe_atribut_id,nilai from aset_m_tipe_atribut_nilai where tenant_id=$1 and tipe_atribut_id=any($2) and deleted_at is null order by urutan,nilai",&[&ctx.tenant_id,&ids]).await?
    };
    for (index, value) in submitted.iter().enumerate() {
        let id = text(value, "tipe_atribut_id");
        if !definitions
            .iter()
            .any(|definition| text(definition, "id") == id)
        {
            return Err(Error::validation(
                &format!("atribut.{index}.tipe_atribut_id"),
                "Atribut ini tidak terdaftar pada jenis aset yang dipilih.",
            ));
        }
    }
    let mut rows = Vec::new();
    for definition in definitions {
        let id = text(&definition, "id");
        let raw = submitted
            .iter()
            .rev()
            .find(|value| text(value, "tipe_atribut_id") == id)
            .map(|value| value["nilai"].clone())
            .unwrap_or(Value::Null);
        let field = format!("atribut.{id}");
        let name = text(&definition, "nama");
        if raw.is_null() || raw == json!("") {
            if flag(&definition, "wajib") {
                return Err(Error::validation(&field, &format!("{name} wajib diisi.")));
            }
            continue;
        }
        let mut row = json!({"id":db::id().to_uppercase(),"tenant_id":ctx.tenant_id,"tipe_atribut_id":id,"nilai_text":null,"nilai_number":null,"nilai_boolean":null,"nilai_date":null,"tipe_atribut_nilai_id":null});
        match text(&definition, "data_type").as_str() {
            "decimal" | "integer" => {
                let value = money::decimal(&raw).map_err(|_| {
                    Error::validation(&field, &format!("{name} harus berupa angka."))
                })?;
                if text(&definition, "data_type") == "integer" && value.fract() != Decimal::ZERO {
                    return Err(Error::validation(
                        &field,
                        &format!("{name} harus berupa bilangan bulat."),
                    ));
                }
                if (!definition["min_value"].is_null()
                    && value < money::decimal(&definition["min_value"])?)
                    || (!definition["max_value"].is_null()
                        && value > money::decimal(&definition["max_value"])?)
                {
                    return Err(Error::validation(
                        &field,
                        &format!(
                            "{name} harus antara {} dan {}.",
                            text(&definition, "min_value"),
                            text(&definition, "max_value")
                        ),
                    ));
                }
                row["nilai_number"] = json!(value.to_string());
            }
            "boolean" => {
                row["nilai_boolean"] = json!(
                    raw == json!(true)
                        || raw == json!(1)
                        || raw.as_str().is_some_and(|value| ["1", "true", "on", "yes"]
                            .contains(&value.to_lowercase().as_str()))
                )
            }
            "date" => {
                let value = raw.as_str().unwrap_or("");
                validate_date(&field, value)?;
                row["nilai_date"] = json!(value);
            }
            "string" => {
                let value = raw
                    .as_str()
                    .map(str::to_owned)
                    .unwrap_or_else(|| raw.to_string());
                let options: Vec<&Value> = choices
                    .iter()
                    .filter(|choice| text(choice, "tipe_atribut_id") == id)
                    .collect();
                if !options.is_empty() {
                    let choice = options
                        .iter()
                        .find(|choice| text(choice, "nilai") == value)
                        .ok_or_else(|| {
                            Error::validation(
                                &field,
                                &format!("{name} harus dipilih dari daftar yang tersedia."),
                            )
                        })?;
                    row["tipe_atribut_nilai_id"] = choice["id"].clone();
                }
                row["nilai_text"] = json!(value);
            }
            _ => {
                return Err(Error::validation(
                    &field,
                    &format!("{name} memakai tipe data yang tidak dikenal."),
                ));
            }
        }
        rows.push(row);
    }
    Ok(rows)
}

async fn create_asset(
    db: &Client,
    ctx: &Context,
    receipt: &Value,
    line: &Value,
    key: &str,
    code: &str,
    value: &str,
) -> Result<()> {
    condition(db, ctx, line).await?;
    let group = db::one(
        db,
        "select * from aset_m_group_aset where tenant_id=$1 and id=$2",
        &[&ctx.tenant_id, &text(line, "group_aset_id")],
    )
    .await?;
    let location =
        nullable(receipt, "lokasi_aset_id").or_else(|| nullable(&group, "lokasi_aset_id"));
    let dimension = location_value(db, ctx, location.clone(), "org_unit_id")
        .await?
        .or_else(|| nullable(receipt, "responsible_org_unit_id"));
    let asset_id = db::id();
    let mut asset = json!({"id":asset_id,"tenant_id":ctx.tenant_id,"creation_key":key,"kode":code,"nama":line["nama"],"legal_entity_id":receipt["legal_entity_id"],"responsible_org_unit_id":receipt["responsible_org_unit_id"],"group_aset_id":line["group_aset_id"],"kelompok_harta_fiskal_id":group["kelompok_harta_fiskal_id"],"jenis_aset_id":line["jenis_aset_id"],"lokasi_aset_id":location,"financial_dimension_org_unit_id":dimension,"serial_number":null,"acquired_on":receipt["tanggal"],"placed_in_service_on":receipt["tanggal_siap_pakai"],"acquisition_value":value,"currency_code":receipt["currency_code"],"lifecycle_state":"received","is_budgeted":false,"penerimaan_aset_id":receipt["id"],"penerimaan_aset_detail_id":line["id"]});
    for field in [
        "kondisi_aset_id",
        "pabrikan_aset_id",
        "model_aset_id",
        "model_number",
        "keterangan",
    ] {
        asset[field] = line[field].clone();
    }
    db::insert(db, "aset_tr_aset", asset, true).await?;
    db::insert(db,"aset_tr_penempatan_aset",json!({"id":db::id().to_uppercase(),"tenant_id":ctx.tenant_id,"aset_id":asset_id,"receiving_org_unit_id":receipt["receiving_org_unit_id"],"usage_org_unit_id":receipt["responsible_org_unit_id"],"received_by_user_id":receipt["diterima_oleh_user_id"],"custodian_user_id":receipt["penanggung_jawab_user_id"],"lokasi_aset_id":location,"effective_on":receipt["tanggal"],"reason":"Penerimaan aset"}),true).await?;
    let receipt_code=db::one(db,"select kode from aset_tr_penerimaan_aset where tenant_id=$1 and id=$2 and deleted_at is null",&[&ctx.tenant_id,&text(receipt,"id")]).await?;
    let context=json!({"type":"acquisition","date":receipt["tanggal"],"source_type":"penerimaan-aset","source_id":receipt["id"],"document_number":receipt_code["kode"],"description":"Perolehan aset"}).to_string();
    db.cached_query_one(
        "select set_config('coreerp.fa_ledger_context',$1,true)",
        &[&context],
    )
    .await?;
    let books=db::rows(db,"select gb.buku_id,gb.useful_life_periods,gb.convention,gb.depreciate,coalesce(gb.round_off_depreciation,b.round_off_depreciation) as round_off_depreciation,coalesce(gb.depreciation_profile_id,b.depreciation_profile_id) as depreciation_profile_id,coalesce(gb.alternative_profile_id,b.alternative_profile_id) as alternative_profile_id,b.kode as buku_code from aset_m_group_buku_penyusutan gb join aset_m_buku_penyusutan b on b.id=gb.buku_id and b.tenant_id=gb.tenant_id where gb.tenant_id=$1 and gb.group_aset_id=$2 and gb.deleted_at is null and b.deleted_at is null and b.aktif",&[&ctx.tenant_id,&text(line,"group_aset_id")]).await?;
    for book in books {
        let depreciates = flag(&book, "depreciate")
            && (group["capitalization_threshold"].is_null()
                || money::decimal(&json!(value))?
                    >= money::decimal(&group["capitalization_threshold"])?);
        let selected_profile = if depreciates {
            Some(profile(db, ctx, &book["depreciation_profile_id"]).await?)
        } else {
            None
        };
        if depreciates && !book["alternative_profile_id"].is_null() {
            profile(db, ctx, &book["alternative_profile_id"]).await?;
        }
        let life = if book["useful_life_periods"].is_null() {
            selected_profile
                .as_ref()
                .map(|profile| profile["useful_life_periods"].clone())
                .unwrap_or(Value::Null)
        } else {
            book["useful_life_periods"].clone()
        };
        let convention = if book["convention"].is_null() {
            selected_profile
                .as_ref()
                .map(|profile| profile["convention"].clone())
                .unwrap_or(Value::Null)
        } else {
            book["convention"].clone()
        };
        let placed =
            nullable(receipt, "tanggal_siap_pakai").unwrap_or_else(|| text(receipt, "tanggal"));
        let start = start_date(&placed, convention.as_str().unwrap_or(""))?;
        db::insert(db,"aset_tr_buku_aset",json!({"id":db::id(),"tenant_id":ctx.tenant_id,"aset_id":asset_id,"buku_id":book["buku_id"],"depreciation_profile_id":book["depreciation_profile_id"],"alternative_profile_id":book["alternative_profile_id"],"book_code":book["buku_code"],"useful_life_periods":life,"convention":convention,"depreciation_start_on":start,"depreciate":depreciates,"round_off_depreciation":book["round_off_depreciation"],"acquisition_value":value,"residual_value":line["residu_per_unit"],"accumulated_depreciation":"0","opening_accumulated_depreciation":"0","elapsed_periods_offset":0,"net_book_value":value,"status":"active"}),true).await?;
    }
    let attributes = line["atribut"].as_array().cloned().unwrap_or_default();
    let rows = attribute_rows(db, ctx, &text(line, "jenis_aset_id"), &attributes).await?;
    let mut types = Vec::new();
    for mut row in rows {
        types.push(text(&row, "tipe_atribut_id"));
        row["aset_id"] = json!(asset_id);
        db::insert(db, "aset_tr_aset_atribut", row, true).await?;
    }
    if !types.is_empty() {
        db.cached_execute("update aset_m_tipe_atribut set data_type_locked=true,updated_at=now() where tenant_id=$1 and id=any($2)",&[&ctx.tenant_id,&types]).await?;
    }
    Ok(())
}
async fn profile(db: &Client, ctx: &Context, id: &Value) -> Result<Value> {
    let id = id.as_str().unwrap_or("");
    let profile=db::optional(db,"select * from aset_m_profil_penyusutan where tenant_id=$1 and id=$2 and aktif and deleted_at is null",&[&ctx.tenant_id,&id]).await?.ok_or_else(||Error::validation("group_aset_id","Profil penyusutan pada Buku atau matriks sudah tidak aktif. Pilih profil yang masih berlaku."))?;
    if text(&profile, "method") != "straight_line"
        || text(&profile, "frequency") != "monthly"
        || text(&profile, "year_basis") != "calendar"
    {
        return Err(Error::unsupported(
            "Profil penyusutan ini belum dicakup kandidat ini.",
        ));
    }
    Ok(profile)
}
fn start_date(date: &str, convention: &str) -> Result<String> {
    let date = NaiveDate::parse_from_str(date, "%Y-%m-%d")
        .map_err(|_| Error::validation("tanggal", "Tanggal tidak sah."))?;
    let first = date.with_day(1).unwrap();
    let result = match convention {
        "full_month" => first,
        "mid_month_1st" => {
            if date.day() <= 15 {
                first
            } else {
                (first + Duration::days(32)).with_day(1).unwrap()
            }
        }
        "mid_month_15th" => first + Duration::days(14),
        "none" | "" => date,
        _ => {
            return Err(Error::unsupported(
                "Konvensi penyusutan belum dicakup kandidat ini.",
            ));
        }
    };
    Ok(result.to_string())
}

async fn asset_show(db: &Client, ctx: &Context, id: &str) -> Result<Response> {
    ctx.require("management-aset.aset.read")?;
    let policies = lists::policy(ctx);
    let row=db::one(db,&format!("select a.* from aset_tr_aset a where a.tenant_id=$1 and a.legal_entity_id=$2 and a.deleted_at is null{} and a.id=$4",lists::scope("a")),&[&ctx.tenant_id,&ctx.company_id,&policies,&id]).await?;
    let mut presented = lists::present_asset(&row, false);
    let rows=db::rows(db,"select t.id as tipe_atribut_id,t.kode,t.nama,t.data_type,t.satuan,a.nilai_text,a.nilai_number,a.nilai_boolean,a.nilai_date,a.tipe_atribut_nilai_id from aset_tr_aset_atribut a join aset_m_tipe_atribut t on t.id=a.tipe_atribut_id and t.tenant_id=a.tenant_id where a.tenant_id=$1 and a.aset_id=$2 order by t.nama",&[&ctx.tenant_id,&id]).await?;
    presented["atribut"]=json!(rows.into_iter().map(|row|json!({"tipe_atribut_id":row["tipe_atribut_id"],"kode":row["kode"],"nama":row["nama"],"data_type":row["data_type"],"satuan":row["satuan"],"tipe_atribut_nilai_id":row["tipe_atribut_nilai_id"],"nilai":match text(&row,"data_type").as_str(){"decimal"|"integer"=>row["nilai_number"].clone(),"boolean"=>row["nilai_boolean"].clone(),"date"=>row["nilai_date"].clone(),_=>row["nilai_text"].clone()}})).collect::<Vec<_>>());
    Ok(version_response(presented))
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn if_match_takes_precedence_and_missing_or_unquoted_is_428() {
        let body = json!({"version":3});
        let mut headers = HeaderMap::new();
        headers.insert("if-match", HeaderValue::from_static("W/\"7\""));
        assert_eq!(expected_version(&headers, &body).unwrap(), 7);
        headers.insert("if-match", HeaderValue::from_static("7"));
        assert_eq!(expected_version(&headers, &body).unwrap_err().status, 428);
        assert_eq!(
            expected_version(&HeaderMap::new(), &json!({}))
                .unwrap_err()
                .status,
            428
        );
    }
}
