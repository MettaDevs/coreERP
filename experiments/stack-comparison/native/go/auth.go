package main

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/md5"
	"crypto/rand"
	"crypto/sha1"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"math/big"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/redis/go-redis/v9"
	"golang.org/x/crypto/bcrypt"
)

const guardKey = "login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d"

func applicationKey(s string) ([]byte, error) {
	if strings.HasPrefix(s, "base64:") {
		b, e := base64.StdEncoding.DecodeString(strings.TrimPrefix(s, "base64:"))
		if e != nil {
			return nil, e
		}
		s = string(b)
	}
	if len(s) != 32 {
		return nil, errors.New("APP_KEY must contain an AES-256 key")
	}
	return []byte(s), nil
}
func randomToken() string {
	b := make([]byte, 30)
	if _, e := rand.Read(b); e != nil {
		panic(e)
	}
	return hex.EncodeToString(b)[:40]
}
func cookiePrefix(name string, key []byte) string {
	h := hmac.New(sha1.New, key)
	h.Write([]byte(name + "v2"))
	return hex.EncodeToString(h.Sum(nil)) + "|"
}
func encryptCookie(name, value string, key []byte) (string, error) {
	block, e := aes.NewCipher(key)
	if e != nil {
		return "", e
	}
	iv := make([]byte, aes.BlockSize)
	if _, e = rand.Read(iv); e != nil {
		return "", e
	}
	data := []byte(cookiePrefix(name, key) + value)
	n := aes.BlockSize - len(data)%aes.BlockSize
	for range n {
		data = append(data, byte(n))
	}
	cipher.NewCBCEncrypter(block, iv).CryptBlocks(data, data)
	i, v := base64.StdEncoding.EncodeToString(iv), base64.StdEncoding.EncodeToString(data)
	h := hmac.New(sha256.New, key)
	h.Write([]byte(i + v))
	payload := object{"iv": i, "value": v, "mac": hex.EncodeToString(h.Sum(nil)), "tag": ""}
	return base64.StdEncoding.EncodeToString([]byte(jsonText(payload))), nil
}
func decryptCookie(name, value string, key []byte) (string, error) {
	b, e := base64.StdEncoding.DecodeString(value)
	if e != nil {
		return "", e
	}
	var envelope map[string]string
	if e = json.Unmarshal(b, &envelope); e != nil {
		return "", e
	}
	h := hmac.New(sha256.New, key)
	h.Write([]byte(envelope["iv"] + envelope["value"]))
	mac, e := hex.DecodeString(envelope["mac"])
	if e != nil || !hmac.Equal(h.Sum(nil), mac) {
		return "", errors.New("invalid cookie MAC")
	}
	iv, e := base64.StdEncoding.DecodeString(envelope["iv"])
	if e != nil || len(iv) != aes.BlockSize {
		return "", errors.New("invalid cookie IV")
	}
	data, e := base64.StdEncoding.DecodeString(envelope["value"])
	if e != nil || len(data) == 0 || len(data)%aes.BlockSize != 0 {
		return "", errors.New("invalid cookie cipher")
	}
	block, e := aes.NewCipher(key)
	if e != nil {
		return "", e
	}
	cipher.NewCBCDecrypter(block, iv).CryptBlocks(data, data)
	n := int(data[len(data)-1])
	if n < 1 || n > aes.BlockSize || n > len(data) {
		return "", errors.New("invalid cookie padding")
	}
	for _, v := range data[len(data)-n:] {
		if int(v) != n {
			return "", errors.New("invalid cookie padding")
		}
	}
	data = data[:len(data)-n]
	prefix := cookiePrefix(name, key)
	if len(data) < len(prefix) || subtle.ConstantTimeCompare(data[:len(prefix)], []byte(prefix)) != 1 {
		return "", errors.New("invalid cookie prefix")
	}
	return string(data[len(prefix):]), nil
}
func (q *request) loadSession() error {
	q.session = object{}
	if cookie, e := q.r.Cookie(q.cookie); e == nil {
		value, _ := url.QueryUnescape(cookie.Value)
		id, e := decryptCookie(q.cookie, value, q.key)
		if e == nil && len(id) == 40 {
			q.sessionID = id
			if e = q.readSession(id); e != nil {
				return e
			}
		}
	}
	if q.sessionID == "" {
		q.sessionID = randomToken()
	}
	if str(q.session["_token"]) == "" {
		q.session["_token"] = randomToken()
	}
	return nil
}

// readSession membaca payload sesi dari Valkey atau tabel sessions sesuai SESSION_BACKEND. Di Valkey, TTL
// menggantikan pemeriksaan last_activity: kunci yang tidak ada berarti sesi tidak ada atau kedaluwarsa.
func (q *request) readSession(id string) error {
	if q.valkey != nil {
		payload, e := q.valkey.Get(q.ctx, q.sessionPrefix+id).Result()
		if errors.Is(e, redis.Nil) {
			return nil
		}
		if e != nil {
			// Sesi yang gagal dibaca tidak boleh ditimpa sesi kosong di akhir request.
			q.session = nil
			return sessionUnavailable(e)
		}
		q.decodeSession(payload)
		return nil
	}
	s, e := q.row("select payload,last_activity from sessions where id=$1", id)
	if e == nil && integer(s["last_activity"]) >= time.Now().Unix()-q.lifetime {
		q.decodeSession(str(s["payload"]))
	} else if e != nil && !errors.Is(e, pgx.ErrNoRows) {
		return e
	}
	return nil
}
func (q *request) decodeSession(payload string) {
	b, e := base64.StdEncoding.DecodeString(payload)
	if e == nil {
		_ = decode(b, &q.session)
	}
}

// sessionUnavailable: kegagalan Valkey menjadi 503 untuk request itu, tidak jatuh diam-diam ke database.
func sessionUnavailable(e error) error {
	log.Printf("session store unavailable: %v", e)
	return fail(503, "Sesi tidak tersedia.")
}

// forgetSession menghapus sesi lama saat logout dan saat id diganti waktu login.
func (q *request) forgetSession() error {
	if q.valkey != nil {
		if e := q.valkey.Del(q.ctx, q.sessionPrefix+q.sessionID).Err(); e != nil {
			return sessionUnavailable(e)
		}
		return nil
	}
	return q.exec("delete from sessions where id=$1", q.sessionID)
}
func (q *request) saveSession(w http.ResponseWriter) error {
	payload := base64.StdEncoding.EncodeToString([]byte(jsonText(q.session)))
	if q.valkey != nil {
		if e := q.valkey.Set(q.ctx, q.sessionPrefix+q.sessionID, payload, time.Duration(q.lifetime)*time.Second).Err(); e != nil {
			return sessionUnavailable(e)
		}
	} else {
		var user any
		if q.user != nil {
			user = q.user["id"]
		}
		ip, _, e := net.SplitHostPort(q.r.RemoteAddr)
		if e != nil {
			ip = q.r.RemoteAddr
		}
		e = q.exec("insert into sessions(id,user_id,ip_address,user_agent,payload,last_activity) values($1,$2,$3,$4,$5,$6) on conflict(id) do update set user_id=excluded.user_id,ip_address=excluded.ip_address,user_agent=excluded.user_agent,payload=excluded.payload,last_activity=excluded.last_activity", q.sessionID, user, ip, q.r.UserAgent(), payload, time.Now().Unix())
		if e != nil {
			return e
		}
	}
	for name, value := range map[string]string{q.cookie: q.sessionID, "XSRF-TOKEN": str(q.session["_token"])} {
		v, e := encryptCookie(name, value, q.key)
		if e != nil {
			return e
		}
		http.SetCookie(w, &http.Cookie{Name: name, Value: url.QueryEscape(v), Path: "/", HttpOnly: name == q.cookie, SameSite: http.SameSiteLaxMode, MaxAge: int(q.lifetime)})
	}
	// Valkey membuang sesi lewat TTL; GC tabel sessions hanya berlaku pada mode database.
	if q.valkey != nil {
		return nil
	}
	lottery, lotteryError := rand.Int(rand.Reader, big.NewInt(100))
	if lotteryError == nil && lottery.Int64() < 2 {
		return q.exec("delete from sessions where last_activity<$1", time.Now().Unix()-q.lifetime)
	}
	return nil
}
func (q *request) csrf() error {
	if q.r.Method == "GET" || q.r.Method == "HEAD" || q.r.Method == "OPTIONS" || q.r.Header.Get("Sec-Fetch-Site") == "same-origin" {
		return nil
	}
	token := q.r.Header.Get("X-CSRF-TOKEN")
	if q.r.Body != nil && strings.HasPrefix(q.r.Header.Get("Content-Type"), "application/json") {
		if b, err := q.body(); err == nil && str(b["_token"]) != "" {
			token = str(b["_token"])
		}
	}
	if token == "" {
		header, _ := url.QueryUnescape(q.r.Header.Get("X-XSRF-TOKEN"))
		token, _ = decryptCookie("XSRF-TOKEN", header, q.key)
	}
	if token == "" || subtle.ConstantTimeCompare([]byte(token), []byte(str(q.session["_token"]))) != 1 {
		return fail(419, "CSRF token mismatch.")
	}
	return nil
}
func (q *request) login() (response, error) {
	b, e := q.body()
	if e != nil {
		return response{}, e
	}
	email := strings.ToLower(str(b["email"]))
	password := str(b["password"])
	if email == "" || password == "" {
		return response{}, invalid("email", "Email dan password wajib diisi.")
	}
	ip, _, _ := net.SplitHostPort(q.r.RemoteAddr)
	throttleKey := email + "|" + ip
	namedKey := fmt.Sprintf("%x", md5.Sum([]byte("login"+throttleKey)))
	throttled, e := q.tooManyAttempts(namedKey)
	if e != nil {
		return response{}, e
	}
	if throttled {
		timer, _, e := q.cacheGet(namedKey + ":timer")
		if e != nil {
			return response{}, e
		}
		now := time.Now().Unix()
		retry := max(0, timer-now)
		return response{}, &failure{status: 429, message: "Too Many Attempts.", headers: http.Header{"X-RateLimit-Limit": {"5"}, "X-RateLimit-Remaining": {"0"}, "Retry-After": {fmt.Sprint(retry)}, "X-RateLimit-Reset": {fmt.Sprint(now + retry)}}}
	}
	if _, e = q.rateHit(namedKey); e != nil {
		return response{}, e
	}
	res, e := q.authenticate(email, password, throttleKey)
	var f *failure
	if e != nil && !errors.As(e, &f) {
		return res, e
	}
	attempts, _, readError := q.cacheGet(namedKey)
	if readError != nil {
		return response{}, readError
	}
	headers := http.Header{"X-RateLimit-Limit": {"5"}, "X-RateLimit-Remaining": {fmt.Sprint(max(0, 5-attempts))}}
	if f != nil {
		f.headers = headers
		return res, e
	}
	res.headers = headers
	return res, nil
}
func (q *request) authenticate(email, password, throttleKey string) (response, error) {
	u, e := q.row("select * from users where email=$1 and account_type='person' limit 1", email)
	if e != nil && !errors.Is(e, pgx.ErrNoRows) {
		return response{}, e
	}
	valid := u != nil && bcrypt.CompareHashAndPassword([]byte(str(u["password"])), []byte(password)) == nil
	if valid && nullable(u["two_factor_secret"]) != nil {
		return response{}, unsupported("Akun dengan autentikasi dua langkah berada di luar profil perbandingan.")
	}
	if valid {
		u, e = q.row("select * from users where email=$1 and account_type='person' limit 1", email)
		if e != nil && !errors.Is(e, pgx.ErrNoRows) {
			return response{}, e
		}
		if u == nil {
			valid = false
		} else {
			valid = bcrypt.CompareHashAndPassword([]byte(str(u["password"])), []byte(password)) == nil
		}
	}
	if !valid {
		if _, e = q.rateHit(throttleKey); e != nil {
			return response{}, e
		}
		return response{}, invalid("email", "These credentials do not match our records.")
	}
	if e = q.forgetSession(); e != nil {
		return response{}, e
	}
	q.sessionID = randomToken()
	q.session[guardKey] = u["id"]
	q.session["password_hash_web"] = u["password"]
	q.session["_token"] = randomToken()
	q.user = u
	if e = q.exec("update users set last_login_at=now(),updated_at=now() where id=$1", u["id"]); e != nil {
		return response{}, e
	}
	if e = q.exec("select set_config('coreerp.user_id',$1,false)", str(u["id"])); e != nil {
		return response{}, e
	}
	for _, key := range []string{throttleKey, throttleKey + ":timer"} {
		if e = q.cacheForget(key); e != nil {
			return response{}, e
		}
	}
	return response{body: object{"two_factor": false}}, nil
}

// Primitif cache dan limiter meniru DatabaseStore dan RateLimiter Laravel per statement; lihat ../../login-limiter.md.
const flexibleCreated = "illuminate:cache:flexible:created:"

func cacheKey(key string) string { return env("CACHE_PREFIX", "laravel-cache-") + key }
func cacheInteger(v any) int64 {
	n := int64(0)
	fmt.Sscanf(str(v), "i:%d;", &n)
	return n
}
func (q *request) cacheGet(key string) (int64, bool, error) {
	entry, e := q.row("select * from cache where key in ($1)", cacheKey(key))
	if errors.Is(e, pgx.ErrNoRows) {
		return 0, false, nil
	} else if e != nil {
		return 0, false, e
	}
	if now := time.Now().Unix(); integer(entry["expiration"]) <= now {
		return 0, false, q.exec("delete from cache where key in ($1,$2) and expiration<=$3", cacheKey(key), cacheKey(flexibleCreated+key), now)
	}
	return cacheInteger(entry["value"]), true, nil
}
func (q *request) cacheAdd(key string, value int64) (bool, error) {
	if _, found, e := q.cacheGet(key); e != nil || found {
		return false, e
	}
	tag, e := q.db.Exec(q.ctx, "insert into cache(key,value,expiration) values($1,$2,$3) on conflict do nothing", cacheKey(key), fmt.Sprintf("i:%d;", value), time.Now().Unix()+60)
	return e == nil && tag.RowsAffected() > 0, e
}
func (q *request) cacheIncrement(key string) (int64, error) {
	hits := int64(0)
	err := q.transaction(func() error {
		entry, e := q.row("select * from cache where key=$1 limit 1 for update", cacheKey(key))
		if errors.Is(e, pgx.ErrNoRows) {
			return nil
		} else if e != nil {
			return e
		}
		hits = cacheInteger(entry["value"]) + 1
		return q.exec("update cache set value=$1 where key=$2", fmt.Sprintf("i:%d;", hits), cacheKey(key))
	})
	return hits, err
}
func (q *request) cacheForget(key string) error {
	return q.exec("delete from cache where key in ($1,$2)", cacheKey(key), cacheKey(flexibleCreated+key))
}
func (q *request) rateHit(key string) (int64, error) {
	if _, e := q.cacheAdd(key+":timer", time.Now().Unix()+60); e != nil {
		return 0, e
	}
	added, e := q.cacheAdd(key, 0)
	if e != nil {
		return 0, e
	}
	hits, e := q.cacheIncrement(key)
	if e != nil || added || hits != 1 {
		return hits, e
	}
	return hits, q.exec("insert into cache(key,value,expiration) values($1,$2,$3) on conflict(key) do update set value=excluded.value,expiration=excluded.expiration", cacheKey(key), "i:1;", time.Now().Unix()+60)
}
func (q *request) tooManyAttempts(key string) (bool, error) {
	if attempts, _, e := q.cacheGet(key); e != nil || attempts < 5 {
		return false, e
	}
	if _, found, e := q.cacheGet(key + ":timer"); e != nil || found {
		return found, e
	}
	return false, q.cacheForget(key)
}
func (q *request) restoreUser() error {
	id := q.session[guardKey]
	if id == nil {
		return fail(401, "Unauthenticated.")
	}
	// Satu-satunya query versi konteks pada request ini; semua bacaan konteks berjalan sesudahnya.
	if e := q.observeContextVersion(); e != nil {
		return e
	}
	u, e := cachedContext(q, "user:"+str(id), nil, func() (object, error) {
		return q.row("select * from users where id=$1 and account_type='person' limit 1", id)
	})
	if errors.Is(e, pgx.ErrNoRows) {
		return fail(401, "Unauthenticated.")
	}
	if e != nil {
		return e
	}
	q.user = u
	if boolean(u["must_change_password"]) {
		return fail(403, "Ganti password sebelum melanjutkan.")
	}
	return q.exec("select set_config('coreerp.user_id',$1,false)", str(u["id"]))
}
func (q *request) logout() (response, error) {
	if e := q.forgetSession(); e != nil {
		return response{}, e
	}
	q.sessionID = randomToken()
	q.session = object{"_token": randomToken()}
	q.user = nil
	return response{status: 204}, nil
}

type grant struct {
	company string
	units   map[string]bool
}
type authority struct {
	tenant, membership, company, unit string
	permissions                       map[string]bool
	all                               bool
	grants                            []grant
	organizations                     []object
}

func (a *authority) allows(company, unit string) bool {
	if company == "" || a.company != company {
		return false
	}
	if a.all {
		return true
	}
	for _, g := range a.grants {
		if (g.company == "" || g.company == company) && (len(g.units) == 0 || g.units[unit]) {
			return true
		}
	}
	return false
}
func (q *request) require(permission string) error {
	if q.authority == nil || !q.authority.permissions["management-aset."+permission] {
		return fail(403, "Forbidden")
	}
	return nil
}
func (q *request) resolveAuthority(selected string) error {
	members, e := cachedContext(q, "memberships:"+str(q.user["id"]), nil, func() ([]object, error) {
		return q.rows("select m.*,t.name as tenant_name from tenant_memberships m join tenants t on t.id=m.tenant_id where m.user_id=$1 and m.status='active' order by m.created_at", q.user["id"])
	})
	if e != nil {
		return e
	}
	if len(members) == 0 {
		return fail(403, "Tidak ada tenant aktif untuk permintaan ini.")
	}
	if selected == "" {
		selected = str(q.session["workspace.membership_id"])
	}
	member := members[0]
	found := false
	for _, m := range members {
		if str(m["id"]) == selected {
			member = m
			found = true
			break
		}
	}
	if selected != "" && !found && q.r.URL.Path == "/api/v1/workspace-context" {
		return invalid("membership_id", "Keanggotaan tidak tersedia.")
	}
	a := &authority{tenant: str(member["tenant_id"]), membership: str(member["id"])}
	scope := a.membership + "|" + a.tenant
	boundary := q.contextBoundary(a.membership, a.tenant)
	if a.permissions, e = cachedContext(q, "permissions:"+scope, boundary, func() (map[string]bool, error) {
		return q.loadPermissions(a.tenant, a.membership)
	}); e != nil {
		return e
	}
	policy, e := cachedContext(q, "policy:"+scope, boundary, func() (*dataPolicy, error) {
		return q.loadDataPolicy(a.tenant, a.membership)
	})
	if e != nil {
		return e
	}
	a.all, a.grants = policy.all, policy.grants
	orgs, e := cachedContext(q, "organizations:"+a.tenant, nil, func() ([]object, error) {
		return q.rows("select * from organizations where tenant_id=$1 and status='active' order by name", a.tenant)
	})
	if e != nil {
		return e
	}
	for _, o := range orgs {
		if policy.anyAll || policy.organizationIDs[str(o["id"])] {
			a.organizations = append(a.organizations, o)
		}
	}
	for _, selection := range []struct {
		class, key string
		target     *string
	}{{"legal_entity", "workspace.legal_entity_id", &a.company}, {"operating_unit", "workspace.org_unit_id", &a.unit}} {
		old := str(q.session[selection.key])
		for _, o := range a.organizations {
			if str(o["classification"]) == selection.class {
				if *selection.target == "" {
					*selection.target = str(o["id"])
				}
				if str(o["id"]) == old {
					*selection.target = old
					break
				}
			}
		}
		q.session[selection.key] = nullable(*selection.target)
	}
	q.session["workspace.membership_id"] = a.membership
	q.authority = a
	if len(a.permissions) == 0 && q.r.URL.Path != "/api/v1/workspace-context" {
		return fail(403, "Tidak ada izin untuk module ini.")
	}
	return nil
}

// loadPermissions menghitung kode izin dari penugasan aktif dan role rekursif.
func (q *request) loadPermissions(tenant, membership string) (map[string]bool, error) {
	permissions := map[string]bool{}
	assigned, e := q.rows("select assignment.role_id from role_assignments assignment join roles on roles.id=assignment.role_id where assignment.membership_id=$1 and assignment.status='active' and roles.is_active=true and assignment.valid_from<=now() and (assignment.valid_until is null or assignment.valid_until>now())", membership)
	if e != nil {
		return nil, e
	}
	ids := []string{}
	for _, r := range assigned {
		ids = append(ids, str(r["role_id"]))
	}
	roles, e := q.rows("with recursive reachable(role_id) as (select r.id from roles r where r.tenant_id=$1 and r.is_active=true and r.id=any($2) union select link.child_role_id from security_role_children link join reachable on reachable.role_id=link.parent_role_id join roles child on child.id=link.child_role_id and child.is_active=true where link.tenant_id=$1) select role_id from reachable", tenant, ids)
	if e != nil {
		return nil, e
	}
	ids = []string{}
	for _, r := range roles {
		ids = append(ids, str(r["role_id"]))
	}
	perms, e := q.rows("select distinct p.code from security_role_duties rd join security_duty_privileges dp on dp.duty_code=rd.duty_code join security_privilege_permissions pp on pp.privilege_code=dp.privilege_code join permissions p on p.code=pp.permission_code where rd.role_id=any($1) and p.app_id='management-aset' order by p.code", ids)
	if e != nil {
		return nil, e
	}
	for _, p := range perms {
		permissions[str(p["code"])] = true
	}
	return permissions, nil
}

// loadDataPolicy membaca scope kebijakan data aktif beserta unit kerja hasil closure hierarki.
func (q *request) loadDataPolicy(tenant, membership string) (*dataPolicy, error) {
	p := &dataPolicy{organizationIDs: map[string]bool{}}
	scopes, e := q.rows("select s.policy_code,s.legal_entity_id,s.organization_id,s.hierarchy_version_id,s.include_descendants from role_assignment_data_policy_scopes s join role_assignments a on a.id=s.role_assignment_id where s.tenant_id=$1 and a.membership_id=$2 and a.status='active' and a.valid_from<=now() and (a.valid_until is null or a.valid_until>now()) and s.valid_from<=now() and (s.valid_until is null or s.valid_until>now())", tenant, membership)
	if e != nil {
		return nil, e
	}
	for _, s := range scopes {
		all := s["legal_entity_id"] == nil && s["organization_id"] == nil
		if all {
			p.anyAll = true
		}
		g := grant{company: str(s["legal_entity_id"]), units: map[string]bool{}}
		if g.company != "" {
			p.organizationIDs[g.company] = true
		}
		id := str(s["organization_id"])
		candidates := []string{}
		if id != "" {
			candidates = append(candidates, id)
		}
		if boolean(s["include_descendants"]) {
			desc, e := q.rows("select descendant_organization_id from organization_hierarchy_closures where version_id=$1 and ancestor_organization_id=$2", s["hierarchy_version_id"], s["organization_id"])
			if e != nil {
				return nil, e
			}
			for _, d := range desc {
				candidates = append(candidates, str(d["descendant_organization_id"]))
			}
		}
		units, e := q.rows("select id from organizations where tenant_id=$1 and classification='operating_unit' and id=any($2)", tenant, candidates)
		if e != nil {
			return nil, e
		}
		for _, u := range units {
			g.units[str(u["id"])] = true
			p.organizationIDs[str(u["id"])] = true
		}
		if str(s["policy_code"]) == "management-aset.asset-responsibility" {
			if all {
				p.all = true
			}
			if g.company != "" || len(g.units) > 0 {
				p.grants = append(p.grants, g)
			}
		}
	}
	return p, nil
}
func (q *request) workspace() (response, error) {
	b, e := q.body()
	if e != nil {
		return response{}, e
	}
	if str(b["membership_id"]) == "" {
		return response{}, invalid("membership_id", "Keanggotaan wajib dipilih.")
	}
	if e = q.resolveAuthority(str(b["membership_id"])); e != nil {
		return response{}, e
	}
	a := q.authority
	for _, key := range []string{"legal_entity_id", "org_unit_id"} {
		wanted := str(b[key])
		if wanted == "" {
			continue
		}
		class := "legal_entity"
		if key == "org_unit_id" {
			class = "operating_unit"
		}
		found := false
		for _, o := range a.organizations {
			if str(o["id"]) == wanted && str(o["classification"]) == class {
				found = true
				break
			}
		}
		if !found {
			return response{}, invalid(key, "Organisasi tidak tersedia.")
		}
		q.session["workspace."+key] = wanted
		if key == "legal_entity_id" {
			a.company = wanted
		} else {
			a.unit = wanted
		}
	}
	return response{body: object{"membership_id": a.membership, "tenant_id": a.tenant, "legal_entity_id": nullable(a.company), "org_unit_id": nullable(a.unit)}}, nil
}
