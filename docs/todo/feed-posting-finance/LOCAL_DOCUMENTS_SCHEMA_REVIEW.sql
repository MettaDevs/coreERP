-- 2026_10_13_143000_add_straight_line_permission_to_asset_classifications
alter table "aset_m_kelompok_harta_fiskal" add column "allow_straight_line" boolean not null default '1';

-- 2026_10_13_160000_create_asset_type_conditions
create table "aset_m_jenis_aset_kondisi" ("id" char(26) not null, "tenant_id" char(26) not null, "jenis_aset_id" char(26) not null, "kondisi_aset_id" char(26) not null, "created_by_user_id" bigint null, "updated_by_user_id" bigint null, "version" integer not null default '1', "deleted_at" timestamp(0) without time zone null, "created_at" timestamp(0) without time zone null, "updated_at" timestamp(0) without time zone null);
alter table "aset_m_jenis_aset_kondisi" add constraint "aset_m_jenis_aset_kondisi_tenant_id_id_unique" unique ("tenant_id", "id");
create index "aset_m_jenis_aset_kondisi_condition_ix" on "aset_m_jenis_aset_kondisi" ("tenant_id", "kondisi_aset_id");
alter table "aset_m_jenis_aset_kondisi" add constraint "aset_m_jenis_aset_kondisi_jenis_fk" foreign key ("tenant_id", "jenis_aset_id") references "aset_m_jenis_aset" ("tenant_id", "id") on delete restrict;
alter table "aset_m_jenis_aset_kondisi" add constraint "aset_m_jenis_aset_kondisi_condition_fk" foreign key ("tenant_id", "kondisi_aset_id") references "aset_m_kondisi_aset" ("tenant_id", "id") on delete restrict;
alter table "aset_m_jenis_aset_kondisi" add primary key ("id");
create index "aset_m_jenis_aset_kondisi_tenant_id_index" on "aset_m_jenis_aset_kondisi" ("tenant_id");
CREATE UNIQUE INDEX aset_m_jenis_aset_kondisi_unique ON aset_m_jenis_aset_kondisi (tenant_id, jenis_aset_id, kondisi_aset_id) WHERE deleted_at IS NULL;
CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON "aset_m_jenis_aset_kondisi" FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor();
CREATE OR REPLACE TRIGGER bump_row_version BEFORE UPDATE ON "aset_m_jenis_aset_kondisi" FOR EACH ROW EXECUTE FUNCTION coreerp_bump_row_version();
CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON "aset_m_jenis_aset_kondisi" FOR EACH ROW EXECUTE FUNCTION coreerp_log_change();

-- 2026_10_13_170000_make_asset_location_code_unique_for_active_records
ALTER TABLE aset_m_lokasi_aset DROP CONSTRAINT m_lokasi_aset_tenant_id_kode_unique;
CREATE UNIQUE INDEX m_lokasi_aset_tenant_id_kode_unique ON aset_m_lokasi_aset (tenant_id, kode) WHERE deleted_at IS NULL;

-- 2026_10_14_100000_add_purchase_order_reference_to_asset_receipts
alter table "aset_tr_penerimaan_aset" add column "purchase_order_reference" varchar(80) null;
