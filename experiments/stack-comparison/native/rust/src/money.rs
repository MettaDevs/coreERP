use crate::db::{Error, Result};
use rust_decimal::{Decimal, RoundingStrategy, prelude::ToPrimitive};
use serde_json::Value;
use std::str::FromStr;

pub fn decimal(value: &Value) -> Result<Decimal> {
    let input = match value {
        Value::String(text) => text.clone(),
        Value::Number(number) => number.to_string(),
        Value::Null => "0".into(),
        _ => String::new(),
    };
    Decimal::from_str(&input)
        .map_err(|_| Error::validation("details", "Isi nilai berupa angka yang sah."))
}
pub fn fixed(value: Decimal, scale: u32) -> String {
    format!(
        "{:.*}",
        scale as usize,
        value.round_dp_with_strategy(scale, RoundingStrategy::MidpointAwayFromZero)
    )
}
pub fn extended(value: &Value, quantity: i64, scale: u32) -> Result<Decimal> {
    Ok((decimal(value)? * Decimal::from(quantity))
        .round_dp_with_strategy(scale, RoundingStrategy::MidpointAwayFromZero))
}
pub fn split(total: Decimal, count: i64, scale: u32) -> Result<Vec<String>> {
    if count <= 0 {
        return Err(Error::validation(
            "details",
            "Jumlah harus lebih besar dari nol.",
        ));
    }
    let multiplier = Decimal::from(10u64.pow(scale));
    let minor = (total * multiplier)
        .to_i64()
        .ok_or_else(|| Error::validation("details", "Nilai terlalu besar."))?;
    let base = minor / count;
    let remainder = minor % count;
    Ok((0..count)
        .map(|index| {
            fixed(
                Decimal::from(base + if index < remainder { 1 } else { 0 }) / multiplier,
                scale,
            )
        })
        .collect())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;
    #[test]
    fn rounds_extended_amount_once_and_assigns_remainder_to_first_unit() {
        let total = extended(&json!("333333.333"), 3, 2).unwrap();
        assert_eq!(fixed(total, 2), "1000000.00");
        assert_eq!(
            split(total, 3, 2).unwrap(),
            ["333333.34", "333333.33", "333333.33"]
        );
        assert_eq!(
            fixed(extended(&json!("33333.333"), 3, 2).unwrap(), 2),
            "100000.00"
        );
    }
    #[test]
    fn uses_half_up_for_decimal_ties_and_preserves_decimal_input() {
        assert_eq!(fixed(decimal(&json!("0.005")).unwrap(), 2), "0.01");
        assert_eq!(fixed(decimal(&json!("-0.005")).unwrap(), 2), "-0.01");
        assert!(decimal(&json!("NaN")).is_err());
    }
}
