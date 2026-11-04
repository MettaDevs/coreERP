package main

import (
	"sync"
	"time"
)

// Cache konteks per proses untuk varian teroptimasi (../../optimized.md, bagian 2). Entri hanya berisi data per
// user, keanggotaan, dan tenant yang disebut di kunci; pemilihan tempat kerja tetap dihitung per request.
const (
	contextVersionQuery  = "select version from security_context_versions where id = 1"
	contextBoundaryQuery = "select min(t) from (select a.valid_until as t from role_assignments a where a.membership_id = $1 and a.status = 'active' and a.valid_until > now() union all select a.valid_from from role_assignments a where a.membership_id = $1 and a.status = 'active' and a.valid_from > now() union all select s.valid_until from role_assignment_data_policy_scopes s join role_assignments a on a.id = s.role_assignment_id where a.membership_id = $1 and s.tenant_id = $2 and s.valid_until > now() union all select s.valid_from from role_assignment_data_policy_scopes s join role_assignments a on a.id = s.role_assignment_id where a.membership_id = $1 and s.tenant_id = $2 and s.valid_from > now()) boundaries"
	contextCacheLimit    = 20000
)

type contextEntry struct {
	value   any
	expires time.Time
}

type contextCache struct {
	ttl     time.Duration
	mu      sync.RWMutex
	version int64
	entries map[string]contextEntry
}

// dataPolicy adalah scope kebijakan data satu keanggotaan pada satu tenant, termasuk unit kerja hasil closure.
type dataPolicy struct {
	anyAll          bool
	all             bool
	organizationIDs map[string]bool
	grants          []grant
}

func newContextCache(ttl time.Duration) *contextCache {
	return &contextCache{ttl: ttl, entries: map[string]contextEntry{}}
}

// observe dipanggil sekali per request dengan versi yang baru dibaca dari database. Versi yang berbeda
// mengosongkan seluruh cache.
func (c *contextCache) observe(version int64) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if version != c.version {
		c.version = version
		c.entries = map[string]contextEntry{}
	}
}

// get hanya melayani request yang membaca versi yang sama dengan versi cache saat ini.
func (c *contextCache) get(version int64, key string, now time.Time) (any, bool) {
	c.mu.RLock()
	defer c.mu.RUnlock()
	entry, ok := c.entries[key]
	if !ok || version != c.version || !now.Before(entry.expires) {
		return nil, false
	}
	return entry.value, true
}

// put membuang data bila versi cache sudah berganti selama request berjalan, karena data itu dibaca sesudah
// versi request, bukan sesudah versi cache yang baru.
func (c *contextCache) put(version int64, key string, value any, expires time.Time) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if version != c.version {
		return
	}
	if len(c.entries) >= contextCacheLimit {
		c.entries = map[string]contextEntry{}
	}
	c.entries[key] = contextEntry{value: value, expires: expires}
}

// expiry = min(sekarang + TTL, batas validitas berikutnya).
func (c *contextCache) expiry(now time.Time, boundary *time.Time) time.Time {
	expires := now.Add(c.ttl)
	if boundary != nil && boundary.Before(expires) {
		return *boundary
	}
	return expires
}

func (q *request) observeContextVersion() error {
	if q.contextCache == nil {
		return nil
	}
	if e := q.db.QueryRow(q.ctx, contextVersionQuery).Scan(&q.cacheVersion); e != nil {
		return e
	}
	q.contextCache.observe(q.cacheVersion)
	return nil
}

// contextBoundary membaca batas validitas penugasan dan scope paling banyak sekali per keanggotaan dalam satu
// request, hanya bila entri kebijakan atau izin miss.
func (q *request) contextBoundary(membership, tenant string) func() (*time.Time, error) {
	var boundary *time.Time
	read := false
	return func() (*time.Time, error) {
		if !read {
			if e := q.db.QueryRow(q.ctx, contextBoundaryQuery, membership, tenant).Scan(&boundary); e != nil {
				return nil, e
			}
			read = true
		}
		return boundary, nil
	}
}

// cachedContext mengambil satu bagian konteks dari cache, atau membacanya dengan query hari ini lalu menyimpannya.
// Saat miss, batas validitas dibaca sebelum data: batas yang lewat di antara keduanya membuat entri langsung
// kedaluwarsa, bukan bertahan sampai TTL dengan penugasan yang sudah berakhir.
func cachedContext[T any](q *request, key string, boundary func() (*time.Time, error), load func() (T, error)) (T, error) {
	c := q.contextCache
	if c == nil {
		return load()
	}
	now := time.Now()
	if v, ok := c.get(q.cacheVersion, key, now); ok {
		return v.(T), nil
	}
	var until *time.Time
	if boundary != nil {
		var e error
		if until, e = boundary(); e != nil {
			var zero T
			return zero, e
		}
	}
	value, e := load()
	if e == nil {
		c.put(q.cacheVersion, key, value, c.expiry(now, until))
	}
	return value, e
}
