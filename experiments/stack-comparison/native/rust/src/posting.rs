use crate::{
    auth::Context,
    db::{self, Error, Result, flag, nullable, number, text},
    money, receipts,
};
use rust_decimal::Decimal;
use serde_json::{Map, Value, json};
use sha2::{Digest, Sha256};
use std::collections::{BTreeMap, HashMap};
use tokio_postgres::Client;

pub struct Evaluation {
    pub preview: Value,
}

pub async fn precision(db: &Client, tenant: &str, currency: &str) -> Result<Value> {
    if let Some(row)=db::optional(db,"select amount_decimals,unit_amount_decimals from currency_precisions where tenant_id=$1 and currency_code=$2",&[&tenant,&currency]).await?{return Ok(row);}
    if currency == "IDR" {
        Ok(json!({"amount_decimals":2,"unit_amount_decimals":3}))
    } else {
        Err(Error::validation(
            "currency_code",
            &format!("Presisi mata uang {currency} belum diatur."),
        ))
    }
}
pub async fn vendor(db: &Client, tenant: &str, id: &str) -> Result<Option<Value>> {
    let Some(mut vendor) = db::optional(
        db,
        "select * from vendors where tenant_id=$1 and id=$2",
        &[&tenant, &id],
    )
    .await?
    else {
        return Ok(None);
    };
    let party = nullable(&vendor, "party_id");
    let party = db::optional(
        db,
        "select id,name from parties where tenant_id=$1 and id=$2",
        &[&tenant, &party],
    )
    .await?;
    vendor["name"] = party
        .map(|party| party["name"].clone())
        .unwrap_or(Value::Null);
    Ok(Some(vendor))
}
pub async fn status(db: &Client, tenant: &str, id: &str) -> Result<Value> {
    let Some(row) = db::optional(
        db,
        "select * from finance_postings where tenant_id=$1 and posting_id=$2",
        &[&tenant, &id],
    )
    .await?
    else {
        return Ok(Value::Null);
    };
    Ok(
        json!({"posting_id":row["posting_id"],"status":row["status"],"settlement_mode":row["settlement_mode"],"posting_date":row["posting_date"],"manual_reason":row["manual_reason"],"external_reference":row["external_reference"],"reason_code":row["reason_code"],"reason":row["reason"],"acknowledged_at":row["acknowledged_at"],"problems":row.get("hold_reasons").filter(|value|!value.is_null()).cloned().unwrap_or(json!([]))}),
    )
}
async fn mode(db: &Client, ctx: &Context, company: &str, date: &str) -> Result<String> {
    Ok(db::optional(db,"select mode from finance_settlement_modes where tenant_id=$1 and legal_entity_id=$2 and effective_from<=$3::text::date order by effective_from desc limit 1",&[&ctx.tenant_id,&company,&date]).await?.map(|row|text(&row,"mode")).unwrap_or_else(||"direct_payable".into()))
}
async fn posted_book(db: &Client, ctx: &Context, group: &str) -> Result<Option<Value>> {
    db::optional(db,"select b.id,b.kode from aset_m_group_buku_penyusutan gb join aset_m_buku_penyusutan b on b.id=gb.buku_id and b.tenant_id=gb.tenant_id where gb.tenant_id=$1 and gb.group_aset_id=$2 and gb.deleted_at is null and b.deleted_at is null and b.aktif and b.posting_layer<>'none' order by case b.posting_layer when 'current' then 0 else 1 end,b.kode limit 1",&[&ctx.tenant_id,&group]).await
}

async fn blocker_values(
    db: &Client,
    ctx: &Context,
    receipt: &Value,
    lines: &[Value],
) -> Result<Map<String, Value>> {
    let mut blockers = Map::new();
    match precision(db, &ctx.tenant_id, &text(receipt, "currency_code")).await {
        Ok(precision) => {
            let amount = number(&precision, "amount_decimals");
            if amount > 2 {
                blockers.insert("currency_code".into(),json!(format!("Presisi nilai {} ({amount} desimal) lebih halus dari yang dapat dicatat register aset (2 desimal). Ubah presisinya di Data referensi › Mata uang.",text(receipt,"currency_code"))));
            }
        }
        Err(error) => {
            blockers.insert("currency_code".into(), error.body["message"].clone());
        }
    }
    if text(receipt, "cara_perolehan") != "pembelian" {
        return Err(Error::unsupported(
            "Cara perolehan ini belum dicakup kandidat ini.",
        ));
    }
    if receipt["vendor_id"].is_null()
        && mode(
            db,
            ctx,
            &text(receipt, "legal_entity_id"),
            &text(receipt, "tanggal"),
        )
        .await?
            == "direct_payable"
    {
        blockers.insert("vendor_id".into(),json!("Pilih vendornya. Entitas legal ini mencatat pembelian aset langsung sebagai hutang ke vendor, jadi vendor wajib diisi."));
    }
    let ids: Vec<String> = lines
        .iter()
        .map(|line| text(line, "group_aset_id"))
        .collect();
    let groups = db::rows(
        db,
        "select id,kode,nama from aset_m_group_aset where tenant_id=$1 and id::text=any($2)",
        &[&ctx.tenant_id, &ids],
    )
    .await?;
    let mut books = HashMap::new();
    for (index, line) in lines.iter().enumerate() {
        let group = text(line, "group_aset_id");
        if !books.contains_key(&group) {
            books.insert(group.clone(), posted_book(db, ctx, &group).await?);
        }
        if books[&group].is_none() {
            let code = groups
                .iter()
                .find(|row| text(row, "id") == group)
                .map(|row| text(row, "kode"))
                .unwrap_or(group);
            blockers.insert(format!("details.{index}.group_aset_id"),json!(format!("Group {code} belum punya buku yang di-post ke finance. Tambahkan buku yang lapisan posting-nya bukan \"none\" di matriks group x buku (Master data › Group aset), lalu selesaikan lagi.")));
        }
    }
    Ok(blockers)
}
pub async fn blockers(db: &Client, ctx: &Context, receipt: &Value, lines: &[Value]) -> Result<()> {
    let blockers = blocker_values(db, ctx, receipt, lines).await?;
    if blockers.is_empty() {
        return Ok(());
    }
    let mut error = Error::new(
        422,
        blockers
            .values()
            .next()
            .and_then(Value::as_str)
            .unwrap_or("Penerimaan belum dapat diselesaikan."),
    );
    error.body["errors"] = json!(
        blockers
            .iter()
            .map(|(field, message)| (field.clone(), json!([message])))
            .collect::<Map<String, Value>>()
    );
    Err(error)
}

struct GroupAmount {
    group: Value,
    unit: String,
    value: Decimal,
    tax: Decimal,
    mapping: Option<Value>,
}

pub async fn evaluate(
    db: &Client,
    ctx: &Context,
    receipt: &Value,
    lines: &[Value],
    assets: &[Value],
    publish: bool,
) -> Result<Evaluation> {
    let blocker_values = if publish {
        Map::new()
    } else {
        blocker_values(db, ctx, receipt, lines).await?
    };
    let blocker_list: Vec<Value> = blocker_values
        .iter()
        .map(|(field, message)| json!({"field":field,"message":message}))
        .collect();
    if blocker_values.contains_key("currency_code") {
        return Ok(Evaluation {
            preview: json!({"blockers":blocker_list,"status":null,"posting_date":null,"settlement_mode":null,"currency":null,"lines":[],"problems":[]}),
        });
    }
    let company = text(receipt, "legal_entity_id");
    let date = text(receipt, "tanggal");
    let currency = text(receipt, "currency_code");
    let code = text(receipt, "kode");
    let receipt_id = text(receipt, "id");
    let precision = precision(db, &ctx.tenant_id, &currency).await?;
    let decimals = number(&precision, "amount_decimals") as u32;
    let mode = mode(db, ctx, &company, &date).await?;
    let groups: Vec<String> = lines
        .iter()
        .map(|line| text(line, "group_aset_id"))
        .collect();
    let groups = db::rows(
        db,
        "select * from aset_m_group_aset where tenant_id=$1 and id::text=any($2)",
        &[&ctx.tenant_id, &groups],
    )
    .await?;
    let mut amounts: Vec<GroupAmount> = Vec::new();
    let mut books: HashMap<String, Option<Value>> = HashMap::new();
    for line in lines {
        let group_id = text(line, "group_aset_id");
        let group = groups
            .iter()
            .find(|group| text(group, "id") == group_id)
            .ok_or_else(|| {
                Error::coded(500, "posting_failed", "Group penerimaan tidak tersedia.")
            })?;
        let location =
            nullable(receipt, "lokasi_aset_id").or_else(|| nullable(group, "lokasi_aset_id"));
        let unit = receipts::location_value(db, ctx, location, "org_unit_id")
            .await?
            .unwrap_or_else(|| text(receipt, "responsible_org_unit_id"));
        if !books.contains_key(&group_id) {
            books.insert(group_id.clone(), posted_book(db, ctx, &group_id).await?);
        }
        let quantity = number(line, "jumlah");
        let value = money::extended(&line["nilai_per_unit"], quantity, decimals)?;
        let tax = money::extended(&line["ppn_per_unit"], quantity, decimals)?;
        if let Some(amount) = amounts
            .iter_mut()
            .find(|amount| text(&amount.group, "id") == group_id && amount.unit == unit)
        {
            amount.value += value;
            amount.tax += tax;
        } else {
            let mapping=db::optional(db,"select * from aset_m_posting_group where tenant_id=$1 and group_aset_id=$2 and deleted_at is null and effective_from<=$3::text::date order by effective_from desc limit 1",&[&ctx.tenant_id,&group_id,&date]).await?;
            amounts.push(GroupAmount {
                group: group.clone(),
                unit,
                value,
                tax,
                mapping,
            });
        }
    }
    let document_vendor = if let Some(vendor_id) = nullable(receipt, "vendor_id") {
        vendor(db, &ctx.tenant_id, &vendor_id).await?
    } else {
        None
    };
    let mut input_lines = Vec::new();
    for amount in &amounts {
        if !amount.value.is_zero() {
            input_lines.push(input_line(
                amount,
                "acquisition_account_id",
                "harga perolehan",
                amount.value,
                Decimal::ZERO,
                &format!("{code} · {}", text(&amount.group, "nama")),
                decimals,
            ));
        }
    }
    for amount in &amounts {
        if !amount.tax.is_zero() {
            input_lines.push(input_line(
                amount,
                "input_vat_account_id",
                "PPN Masukan",
                amount.tax,
                Decimal::ZERO,
                &format!("PPN Masukan · {code} · {}", text(&amount.group, "nama")),
                decimals,
            ));
        }
    }
    for amount in &amounts {
        let total = amount.value + amount.tax;
        if !total.is_zero() {
            let description = if let Some(vendor) = &document_vendor {
                format!("{} · {code}", text(vendor, "name"))
            } else {
                format!("{code} · {}", text(&amount.group, "nama"))
            };
            let (column, label) = if mode == "clearing" {
                ("clearing_account_id", "perantara")
            } else {
                ("payable_account_id", "lawan hutang")
            };
            input_lines.push(input_line(
                amount,
                column,
                label,
                Decimal::ZERO,
                total,
                &description,
                decimals,
            ));
        }
    }
    if input_lines.is_empty() {
        return Ok(Evaluation {
            preview: json!({"blockers":blocker_list,"status":null,"posting_date":null,"settlement_mode":null,"currency":null,"lines":[],"problems":[]}),
        });
    }
    let description = nullable(receipt, "keterangan")
        .filter(|value| !value.trim().is_empty())
        .unwrap_or_else(|| format!("Penerimaan aset {code}"));
    let time = chrono::Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Secs, false);
    let asset_details:Vec<Value>=assets.iter().map(|asset|{let group=text(asset,"group_aset_id");json!({"asset_code":asset["asset_code"],"asset_group":groups.iter().find(|row|text(row,"id")==group).map(|row|row["kode"].clone()).unwrap_or(Value::Null),"book":books.get(&group).and_then(|book|book.as_ref()).map(|book|book["kode"].clone()).unwrap_or(Value::Null),"acquisition_value":asset["acquisition_value"],"tax_amount":asset["tax_amount"]})}).collect();
    let posting_id = format!("AST-ACQ-{receipt_id}");
    let input = json!({"tenant_id":ctx.tenant_id,"posting_id":posting_id,"posting_type":"asset.acquisition","legal_entity_id":company,"currency_code":currency,"posting_date":date,"document_date":nullable(receipt,"vendor_invoice_date").unwrap_or(date.clone()),"occurred_at":time,"settlement_mode":mode,"requires_vendor":mode=="direct_payable","vendor_id":receipt["vendor_id"],"vendor_invoice_reference":receipt["vendor_invoice_reference"],"source_document":{"module":"management-aset","type":"penerimaan-aset","number":code,"description":description.chars().take(255).collect::<String>(),"id":receipt_id,"url":format!("/management-aset/inventarisasi-aset/penerimaan/{receipt_id}")},"lines":input_lines,"details":{"receipt":{"id":receipt_id,"number":code,"purchase_order_reference":receipt["purchase_order_reference"],"attachment_record_type":"aset_tr_penerimaan_aset"},"assets":asset_details}});
    let existing = db::optional(
        db,
        "select * from finance_postings where tenant_id=$1 and posting_id=$2",
        &[&ctx.tenant_id, &posting_id],
    )
    .await?;
    let legal=db::one(db,"select * from organizations where tenant_id=$1 and id=$2 and classification='legal_entity'",&[&ctx.tenant_id,&company]).await?;
    let legal_code = db::optional(
        db,
        "select company_code from legal_entities where organization_id=$1",
        &[&text(&legal, "id")],
    )
    .await?;
    let normalized_vendor = if let Some(id) = nullable(receipt, "vendor_id") {
        let value = vendor(db, &ctx.tenant_id, &id).await?.ok_or_else(|| {
            Error::coded(
                500,
                "posting_failed",
                "vendor_id bukan vendor entitas legal ini.",
            )
        })?;
        if value["legal_entity_id"] != receipt["legal_entity_id"] {
            return Err(Error::coded(
                500,
                "posting_failed",
                "vendor_id bukan vendor entitas legal ini.",
            ));
        }
        json!({"id":value["id"],"number":value["number"],"name":value["name"]})
    } else {
        if publish && mode == "direct_payable" {
            return Err(Error::coded(
                500,
                "posting_failed",
                "Posting ini wajib membawa vendor: perolehan lewat pembelian dengan mode direct_payable.",
            ));
        }
        Value::Null
    };
    let mut normalized = Vec::new();
    let mut debit_total = Decimal::ZERO;
    let mut credit_total = Decimal::ZERO;
    for (index, line) in input_lines.iter().enumerate() {
        let debit = money::decimal(&line["debit"])?;
        let credit = money::decimal(&line["credit"])?;
        if debit < Decimal::ZERO
            || credit < Decimal::ZERO
            || (debit > Decimal::ZERO) == (credit > Decimal::ZERO)
        {
            return Err(Error::coded(
                500,
                "posting_failed",
                "Setiap baris jurnal harus memiliki tepat satu sisi debit atau kredit.",
            ));
        }
        debit_total += debit;
        credit_total += credit;
        let mut line = line.clone();
        line["line_no"] = json!(index + 1);
        line["debit"] = json!(money::fixed(debit, decimals));
        line["credit"] = json!(money::fixed(credit, decimals));
        line["mapping"].as_object_mut().unwrap().remove("reference");
        normalized.push(line);
    }
    if debit_total != credit_total {
        return Err(Error::coded(
            500,
            "posting_failed",
            "Jurnal tidak seimbang.",
        ));
    }
    let hash_lines: Vec<Value> = normalized
        .iter()
        .map(|line| {
            json!([
                line["account_id"],
                line["debit"],
                line["credit"],
                line["org_unit_id"]
            ])
        })
        .collect();
    let hash_input = json!([
        "asset.acquisition",
        company,
        currency,
        date,
        input["document_date"],
        mode,
        receipt["vendor_id"],
        null,
        null,
        hash_lines
    ]);
    let input_hash = hex::encode(Sha256::digest(serde_json::to_vec(&hash_input).unwrap()));
    if let Some(existing) = existing {
        if text(&existing, "input_hash") != input_hash {
            return Err(Error::coded(
                500,
                "posting_failed",
                "posting_id sudah digunakan untuk isi jurnal yang berbeda.",
            ));
        }
        return Ok(Evaluation {
            preview: preview_value(
                &blocker_list,
                &text(&existing, "status"),
                &existing["payload"],
                existing["hold_reasons"]
                    .as_array()
                    .cloned()
                    .unwrap_or_default(),
            ),
        });
    }
    let accounts: Vec<String> = normalized
        .iter()
        .filter_map(|line| nullable(line, "account_id"))
        .collect();
    let accounts: HashMap<String, Value> = db::rows(
        db,
        "select * from finance_reference_accounts where tenant_id=$1 and id::text=any($2)",
        &[&ctx.tenant_id, &accounts],
    )
    .await?
    .into_iter()
    .map(|account| (text(&account, "id"), account))
    .collect();
    let units: Vec<String> = normalized
        .iter()
        .filter_map(|line| nullable(line, "org_unit_id"))
        .collect();
    let units:HashMap<String,Value>=db::rows(db,"select o.id,o.name,o.classification,u.type,u.number from organizations o left join operating_units u on u.organization_id=o.id where o.tenant_id=$1 and o.id::text=any($2)",&[&ctx.tenant_id,&units]).await?.into_iter().map(|unit|(text(&unit,"id"),unit)).collect();
    let unit_ids: Vec<String> = units.keys().cloned().collect();
    let business_units = business_units(db, ctx, &unit_ids, &date).await?;
    let mut problems = Vec::new();
    let mut payload_lines = Vec::new();
    let mut table_lines = Vec::new();
    for line in &normalized {
        let no = number(line, "line_no");
        let account_id = nullable(line, "account_id");
        let account = account_id.as_ref().and_then(|id| accounts.get(id));
        let label = text(&line["mapping"], "label");
        let mapping_fix = json!({"label":"Buka pemetaan akun","url":"/management-aset/fixed-aset-posting-profiles"});
        if account_id.is_none() {
            problems.push(problem(
                no,
                "ACCOUNT_NOT_MAPPED",
                &format!("{label} belum dipetakan ke akun."),
                Value::Null,
                mapping_fix.clone(),
            ));
        } else if account.is_none() {
            problems.push(problem(
                no,
                "ACCOUNT_UNKNOWN",
                &format!("{label} menunjuk akun yang tidak ada di daftar akun."),
                json!({"type":"account","id":account_id,"label":label}),
                mapping_fix.clone(),
            ));
        } else if let Some(account) = account {
            let object = json!({"type":"account","id":account["id"],"label":format!("{} {}",text(account,"code"),text(account,"name"))});
            if !flag(account, "active") {
                problems.push(problem(
                    no,
                    "ACCOUNT_INACTIVE",
                    &format!(
                        "Akun {} {} nonaktif.",
                        text(account, "code"),
                        text(account, "name")
                    ),
                    object,
                    mapping_fix.clone(),
                ));
            } else if !account["legal_entity_id"].is_null()
                && text(account, "legal_entity_id") != company
            {
                problems.push(problem(
                    no,
                    "ACCOUNT_OTHER_LEGAL_ENTITY",
                    &format!(
                        "Akun {} {} khusus entitas legal lain.",
                        text(account, "code"),
                        text(account, "name")
                    ),
                    object,
                    mapping_fix.clone(),
                ));
            }
        }
        let unit_id = nullable(line, "org_unit_id");
        let unit = unit_id.as_ref().and_then(|id| units.get(id));
        let mut dimensions = Vec::new();
        let mut bu_code = Value::Null;
        let mut department_code = Value::Null;
        if unit_id.is_none() {
            problems.push(problem(
                no,
                "DIMENSION_SOURCE_MISSING",
                "Baris ini tidak menyebut unit organisasi, jadi dimensinya tidak dapat dibentuk.",
                Value::Null,
                Value::Null,
            ));
        } else if unit.is_none_or(|unit| text(unit, "classification") != "operating_unit") {
            problems.push(problem(
                no,
                "ORG_UNIT_UNKNOWN",
                "Unit organisasi baris ini tidak ditemukan.",
                json!({"type":"organization","id":unit_id,"label":unit_id}),
                Value::Null,
            ));
        } else if let Some(unit) = unit {
            let id = unit_id.as_ref().unwrap();
            let object = json!({"type":"organization","id":id,"label":unit["name"]});
            if let Some(bu) = business_units.get(id).and_then(|value| value.as_ref()) {
                if bu["number"].is_null() {
                    problems.push(problem(no,"BUSINESS_UNIT_NUMBER_MISSING",&format!("{} belum punya nomor unit.",text(bu,"name")),json!({"type":"organization","id":bu["id"],"label":bu["name"]}),json!({"label":"Buka organisasi","url":"/settings/organization?section=operating-units"})));
                } else {
                    bu_code = bu["number"].clone();
                    dimensions.push(dimension("BUSINESS_UNIT", "Business unit", bu));
                }
            } else {
                problems.push(problem(no,"BUSINESS_UNIT_UNRESOLVED",&format!("{} tidak berada di bawah tepat satu business unit pada hierarki manajemen yang berlaku {date}.",text(unit,"name")),object.clone(),json!({"label":"Buka hierarki organisasi","url":"/settings/organization?section=hierarchies"})));
            }
            if account.is_some_and(|account| text(account, "type") == "profit_loss") {
                if text(unit, "type") != "department" {
                    problems.push(problem(
                        no,
                        "DEPARTMENT_REQUIRED",
                        &format!(
                            "Akun laba rugi {} membutuhkan department, tetapi {} bukan department.",
                            account
                                .map(|account| text(account, "code"))
                                .unwrap_or_default(),
                            text(unit, "name")
                        ),
                        object,
                        Value::Null,
                    ));
                } else if unit["number"].is_null() {
                    problems.push(problem(no,"DEPARTMENT_NUMBER_MISSING",&format!("{} belum punya nomor unit.",text(unit,"name")),object,json!({"label":"Buka organisasi","url":"/settings/organization?section=operating-units"})));
                } else {
                    department_code = unit["number"].clone();
                    dimensions.push(dimension("DEPARTMENT", "Department", unit));
                }
            }
        }
        payload_lines.push(json!({"line_no":no,"account":account.map(|account|json!({"external_id":account["external_id"],"code":account["code"],"name":account["name"]})),"debit":line["debit"],"credit":line["credit"],"description":line["description"],"financial_dimensions":dimensions}));
        table_lines.push(json!({"line_no":no,"account_id":account.map(|account|account["id"].clone()),"account_external_id":account.map(|account|account["external_id"].clone()),"account_code":account.map(|account|account["code"].clone()),"debit":line["debit"],"credit":line["credit"],"description":line["description"],"org_unit_id":line["org_unit_id"],"business_unit_code":bu_code,"department_code":department_code}));
    }
    let total = money::fixed(debit_total, decimals);
    let published_at = chrono::Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Secs, false);
    let payload = json!({
        "contract_version": 1,
        "posting_id": posting_id,
        "posting_type": "asset.acquisition",
        "settlement_mode": mode,
        "legal_entity": {"id": company, "code": legal_code.map(|row|row["company_code"].clone())},
        "currency": {"code": currency, "decimals": decimals},
        "posting_date": date,
        "document_date": input["document_date"],
        "occurred_at": time,
        "published_at": published_at,
        "source_document": {
            "module": "management-aset",
            "type": "penerimaan-aset",
            "number": code,
            "description": input["source_document"]["description"]
        },
        "vendor": normalized_vendor,
        "vendor_invoice_reference": receipt["vendor_invoice_reference"],
        "journal_lines": payload_lines,
        "totals": {"debit": total, "credit": total},
        "reverses_posting_id": null,
        "adjusts_posting_id": null,
        "details": input["details"]
    });
    let settings = db::optional(
        db,
        "select * from finance_posting_settings where tenant_id=$1 and legal_entity_id=$2",
        &[&ctx.tenant_id, &company],
    )
    .await?;
    let (state, reason) = if settings
        .as_ref()
        .is_none_or(|setting| !flag(setting, "enabled"))
    {
        ("manual", Some("feed_disabled"))
    } else if settings
        .as_ref()
        .and_then(|setting| nullable(setting, "cutover_date"))
        .is_some_and(|cutover| date < cutover)
    {
        ("manual", Some("before_cutover"))
    } else if !problems.is_empty() {
        ("held", None)
    } else {
        ("pending", None)
    };
    if publish {
        db.batch_execute("savepoint finance_publication").await?;
        let record = json!({
            "id": db::id(),
            "tenant_id": ctx.tenant_id,
            "legal_entity_id": company,
            "posting_id": posting_id,
            "posting_type": "asset.acquisition",
            "contract_version": 1,
            "source_module": "management-aset",
            "source_type": "penerimaan-aset",
            "source_number": code,
            "source_id": receipt_id,
            "currency_code": currency,
            "currency_decimals": decimals,
            "posting_date": date,
            "document_date": input["document_date"],
            "occurred_at": input["occurred_at"],
            "published_at": payload["published_at"],
            "settlement_mode": mode,
            "status": state,
            "manual_reason": reason,
            "hold_reasons": if state == "held" {json!(problems)} else {Value::Null},
            "vendor_id": receipt["vendor_id"],
            "reverses_posting_id": null,
            "adjusts_posting_id": null,
            "total_debit": total,
            "total_credit": total,
            "payload": payload,
            "input": input,
            "input_hash": input_hash
        });
        let posting = db::insert(db, "finance_postings", record, true).await?;
        let posting_id = text(&posting, "id");
        for line in &mut table_lines {
            line["id"] = json!(db::id().to_uppercase());
            line["tenant_id"] = json!(ctx.tenant_id);
            line["finance_posting_id"] = json!(posting_id);
            line["created_at"] = json!(db::now());
            line["updated_at"] = json!(db::now());
        }
        let fields = "id,tenant_id,finance_posting_id,line_no,account_id,account_external_id,account_code,debit,credit,description,org_unit_id,business_unit_code,department_code,created_at,updated_at";
        for chunk in table_lines.chunks(500) {
            db.execute(&format!("insert into finance_posting_lines({fields}) select {fields} from json_populate_recordset(null::finance_posting_lines,$1)"),&[&json!(chunk)]).await?;
        }
        db::insert(db,"finance_posting_events",json!({"id":db::id(),"tenant_id":ctx.tenant_id,"finance_posting_id":posting_id,"event":"published","from_status":null,"to_status":state,"integration_client_id":null,"user_id":null,"data":{"problems":if state=="held"{problems.len()}else{0}},"created_at":db::now()}),false).await?;
        db.batch_execute("release savepoint finance_publication")
            .await?;
    }
    Ok(Evaluation {
        preview: preview_value(
            &blocker_list,
            state,
            &payload,
            if state == "held" { problems } else { vec![] },
        ),
    })
}

fn input_line(
    amount: &GroupAmount,
    column: &str,
    label: &str,
    debit: Decimal,
    credit: Decimal,
    description: &str,
    decimals: u32,
) -> Value {
    let group = text(&amount.group, "id");
    json!({"account_id":amount.mapping.as_ref().map(|mapping|mapping[column].clone()).unwrap_or(Value::Null),"debit":if debit.is_zero(){"0".to_string()}else{money::fixed(debit,decimals)},"credit":if credit.is_zero(){"0".to_string()}else{money::fixed(credit,decimals)},"description":description.chars().take(255).collect::<String>(),"org_unit_id":amount.unit,"mapping":{"label":format!("Group {} · {label}",text(&amount.group,"kode")),"fix_url":"/management-aset/fixed-aset-posting-profiles","reference":format!("posting-group:{group}:{column}")}})
}
fn dimension(code: &str, name: &str, unit: &Value) -> Value {
    json!({"code":code,"display_name":name,"value_code":unit["number"],"value_display_name":unit["name"],"value_id":unit["id"]})
}
fn problem(line: i64, code: &str, message: &str, object: Value, fix: Value) -> Value {
    json!({"line_no":line,"code":code,"message":message,"object":object,"fix":fix})
}
fn preview_value(blockers: &[Value], status: &str, payload: &Value, problems: Vec<Value>) -> Value {
    let lines:Vec<Value>=payload["journal_lines"].as_array().unwrap_or(&vec![]).iter().map(|line|json!({"line_no":line["line_no"],"account_code":line["account"]["code"],"account_name":line["account"]["name"],"description":line["description"],"debit":line["debit"],"credit":line["credit"],"dimensions":line["financial_dimensions"].as_array().unwrap_or(&vec![]).iter().map(|dimension|json!({"code":dimension["code"],"display_name":dimension["display_name"],"value_code":dimension["value_code"],"value_display_name":dimension["value_display_name"]})).collect::<Vec<_>>()})).collect();
    json!({"blockers":blockers,"status":status,"posting_date":payload["posting_date"],"settlement_mode":payload["settlement_mode"],"currency":payload["currency"],"lines":lines,"problems":problems})
}

async fn business_units(
    db: &Client,
    ctx: &Context,
    units: &[String],
    date: &str,
) -> Result<HashMap<String, Option<Value>>> {
    let versions=db::rows(db,"select v.id,v.hierarchy_id from organization_hierarchy_versions v join organization_hierarchies h on h.id=v.hierarchy_id join organization_hierarchy_purposes hp on hp.hierarchy_id=h.id join hierarchy_purposes p on p.id=hp.purpose_id where h.tenant_id=$1 and h.status='active' and p.code='management' and v.status='published' and v.effective_from<($2::text::date+interval '1 day') order by v.effective_from desc,v.version_number desc",&[&ctx.tenant_id,&date]).await?;
    let mut effective = BTreeMap::new();
    for version in versions {
        effective
            .entry(text(&version, "hierarchy_id"))
            .or_insert(text(&version, "id"));
    }
    let versions: Vec<String> = effective.into_values().collect();
    let mut result: HashMap<String, Option<Value>> =
        units.iter().map(|unit| (unit.clone(), None)).collect();
    if versions.is_empty() || units.is_empty() {
        return Ok(result);
    }
    let rows=db::rows(db,"select c.version_id,c.descendant_organization_id,a.id,a.name,u.number,c.distance from organization_hierarchy_closures c join operating_units u on u.organization_id=c.ancestor_organization_id join organizations a on a.id=c.ancestor_organization_id where c.version_id::text=any($1) and c.descendant_organization_id::text=any($2) and u.type='business_unit' and a.tenant_id=$3 order by c.distance",&[&versions,&units,&ctx.tenant_id]).await?;
    let mut nearest = HashMap::new();
    for row in rows {
        nearest
            .entry((
                text(&row, "descendant_organization_id"),
                text(&row, "version_id"),
            ))
            .or_insert(row);
    }
    for unit in units {
        let mut candidates = HashMap::new();
        for ((descendant, _), row) in &nearest {
            if descendant == unit {
                candidates.insert(text(row, "id"), row.clone());
            }
        }
        if candidates.len() == 1 {
            result.insert(unit.clone(), candidates.into_values().next());
        }
    }
    Ok(result)
}
