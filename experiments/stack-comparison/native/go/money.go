package main

import (
	"errors"
	"fmt"
	"sort"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/shopspring/decimal"
)

func decimalValue(v any) (decimal.Decimal, error) {
	if v == nil || v == "" {
		return decimal.Zero, nil
	}
	return decimal.NewFromString(str(v))
}
func money(v any) decimal.Decimal {
	d, e := decimalValue(v)
	if e != nil {
		panic(e)
	}
	return d
}
func extended(value any, quantity int64, precision int32) string {
	return money(value).Mul(decimal.NewFromInt(quantity)).Round(precision).StringFixed(precision)
}
func allocate(total string, quantity int64, precision int32) []string {
	units := money(total).Shift(precision).IntPart()
	base, remainder := units/quantity, units%quantity
	result := make([]string, quantity)
	for i := int64(0); i < quantity; i++ {
		v := base
		if i < remainder {
			v++
		}
		result[i] = decimal.NewFromInt(v).Shift(-precision).StringFixed(precision)
	}
	return result
}
func (q *request) precision(currency string) (int32, int32, error) {
	r, e := q.row("select amount_decimals,unit_amount_decimals from currency_precisions where tenant_id=$1 and currency_code=$2", q.authority.tenant, currency)
	if errors.Is(e, pgx.ErrNoRows) && currency == "IDR" {
		return 2, 3, nil
	}
	if errors.Is(e, pgx.ErrNoRows) {
		return 0, 0, invalid("currency_code", "Presisi mata uang belum ditetapkan.")
	}
	if e != nil {
		return 0, 0, e
	}
	return int32(integer(r["amount_decimals"])), int32(integer(r["unit_amount_decimals"])), nil
}
func (q *request) insert(table string, value object) error {
	value = takeMap(value)
	if _, ok := value["created_at"]; !ok {
		value["created_at"] = time.Now().UTC()
	}
	if table != "finance_posting_events" {
		if _, ok := value["updated_at"]; !ok {
			value["updated_at"] = time.Now().UTC()
		}
	}
	keys := make([]string, 0, len(value))
	for k := range value {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	args := []any{}
	params := []string{}
	for i, k := range keys {
		args = append(args, value[k])
		params = append(params, fmt.Sprintf("$%d", i+1))
	}
	return q.exec("insert into "+table+" ("+strings.Join(keys, ",")+") values("+strings.Join(params, ",")+")", args...)
}
func takeMap(m object) object {
	n := object{}
	for k, v := range m {
		n[k] = v
	}
	return n
}
