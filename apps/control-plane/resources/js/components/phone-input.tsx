import { useState, useEffect } from 'react';
import { parsePhoneNumberFromString, CountryCode } from 'libphonenumber-js';
import { CheckCircle2, AlertCircle, Phone, ChevronDown } from 'lucide-react';

export type CountryOption = {
    code: CountryCode;
    name: string;
    dialCode: string;
    flag: string;
    example: string;
};

export const COUNTRIES: CountryOption[] = [
    { code: 'ID', name: 'Indonesia', dialCode: '+62', flag: '🇮🇩', example: '812 3456 7890' },
    { code: 'MY', name: 'Malaysia', dialCode: '+60', flag: '🇲🇾', example: '12 345 6789' },
    { code: 'SG', name: 'Singapore', dialCode: '+65', flag: '🇸🇬', example: '8123 4567' },
    { code: 'AU', name: 'Australia', dialCode: '+61', flag: '🇦🇺', example: '412 345 678' },
    { code: 'US', name: 'United States', dialCode: '+1', flag: '🇺🇸', example: '202 555 0123' },
    { code: 'GB', name: 'United Kingdom', dialCode: '+44', flag: '🇬🇧', example: '7911 123456' },
    { code: 'JP', name: 'Japan', dialCode: '+81', flag: '🇯🇵', example: '90 1234 5678' },
    { code: 'KR', name: 'South Korea', dialCode: '+82', flag: '🇰🇷', example: '10 1234 5678' },
    { code: 'SA', name: 'Saudi Arabia', dialCode: '+966', flag: '🇸🇦', example: '51 234 5678' },
    { code: 'AE', name: 'UAE', dialCode: '+971', flag: '🇦🇪', example: '50 123 4567' },
];

type PhoneInputProps = {
    id?: string;
    name?: string;
    value: string;
    onChange: (e164Value: string, isValid: boolean, rawValue: string) => void;
    onBlur?: () => void;
    placeholder?: string;
    className?: string;
    error?: string;
    disabled?: boolean;
};

export default function PhoneInput({
    id = 'phone_number',
    name = 'phone_number',
    value,
    onChange,
    onBlur,
    placeholder,
    className = '',
    error,
    disabled = false,
}: PhoneInputProps) {
    const [selectedCountry, setSelectedCountry] = useState<CountryOption>(COUNTRIES[0]);
    const [displayVal, setDisplayVal] = useState<string>('');
    const [touched, setTouched] = useState<boolean>(false);

    // Initialize/sync displayVal from external value if present
    useEffect(() => {
        if (!value) {
            setDisplayVal('');
            return;
        }

        const parsed = parsePhoneNumberFromString(value, selectedCountry.code);
        if (parsed) {
            setDisplayVal(parsed.formatNational());
            const matchedCountry = COUNTRIES.find((c) => c.code === parsed.country);
            if (matchedCountry) {
                setSelectedCountry(matchedCountry);
            }
        } else if (!displayVal) {
            setDisplayVal(value.replace(selectedCountry.dialCode, '').trim());
        }
    }, [value]);

    const handleInputChange = (rawInputValue: string) => {
        setDisplayVal(rawInputValue);
        if (!touched) setTouched(true);

        const trimmed = rawInputValue.trim();
        if (!trimmed) {
            onChange('', true, '');
            return;
        }

        // Prepare full number string for libphonenumber
        let fullStringToParse = trimmed;
        if (!trimmed.startsWith('+')) {
            // Remove leading zero if user typed e.g. 08123456789
            const digits = trimmed.replace(/^0+/, '');
            fullStringToParse = `${selectedCountry.dialCode}${digits}`;
        }

        const parsed = parsePhoneNumberFromString(fullStringToParse, selectedCountry.code);
        const isValid = parsed ? parsed.isValid() : false;
        const e164 = parsed && isValid ? parsed.number : fullStringToParse;

        onChange(e164, isValid, rawInputValue);
    };

    const handleCountryChange = (country: CountryOption) => {
        setSelectedCountry(country);
        if (!touched) setTouched(true);

        if (displayVal.trim()) {
            let digits = displayVal.trim().replace(/^0+/, '');
            const fullStringToParse = `${country.dialCode}${digits}`;
            const parsed = parsePhoneNumberFromString(fullStringToParse, country.code);
            const isValid = parsed ? parsed.isValid() : false;
            const e164 = parsed && isValid ? parsed.number : fullStringToParse;

            onChange(e164, isValid, displayVal);
        } else {
            onChange('', true, '');
        }
    };

    const handleBlurInternal = () => {
        setTouched(true);

        // Auto-format display value on blur if valid
        if (displayVal.trim()) {
            let fullStringToParse = displayVal.trim();
            if (!fullStringToParse.startsWith('+')) {
                const digits = fullStringToParse.replace(/^0+/, '');
                fullStringToParse = `${selectedCountry.dialCode}${digits}`;
            }

            const parsed = parsePhoneNumberFromString(fullStringToParse, selectedCountry.code);
            if (parsed && parsed.isValid()) {
                setDisplayVal(parsed.formatNational());
            }
        }

        if (onBlur) onBlur();
    };

    // Calculate validation state
    const isEmpty = !displayVal.trim();
    let isPhoneValid = true;
    if (!isEmpty) {
        let fullStringToParse = displayVal.trim();
        if (!fullStringToParse.startsWith('+')) {
            const digits = fullStringToParse.replace(/^0+/, '');
            fullStringToParse = `${selectedCountry.dialCode}${digits}`;
        }
        const parsed = parsePhoneNumberFromString(fullStringToParse, selectedCountry.code);
        isPhoneValid = parsed ? parsed.isValid() : false;
    }

    const showInputError = (touched || error) && !isEmpty && !isPhoneValid;
    const showSuccess = !isEmpty && isPhoneValid && !error;

    return (
        <div className={`space-y-1.5 ${className}`}>
            <label htmlFor={id} className="block text-xs font-bold text-slate-900 dark:text-slate-100">
                Nomor Telepon <span className="font-normal text-slate-500"></span>
            </label>

            <div className="relative flex items-center">
                {/* Country Code Dropdown Button */}
                <div className="relative shrink-0">
                    <select
                        aria-label="Kode Negara"
                        value={selectedCountry.code}
                        onChange={(e) => {
                            const found = COUNTRIES.find((c) => c.code === e.target.value);
                            if (found) handleCountryChange(found);
                        }}
                        disabled={disabled}
                        className="absolute inset-0 w-full h-full opacity-0 cursor-pointer disabled:cursor-not-allowed z-10"
                    >
                        {COUNTRIES.map((c) => (
                            <option key={c.code} value={c.code}>
                                {c.name} ({c.dialCode})
                            </option>
                        ))}
                    </select>

                    <div className="h-9.5 px-2.5 rounded-l-xl bg-slate-50 dark:bg-slate-800 border border-r-0 border-slate-200 dark:border-slate-700 flex items-center gap-1.5 text-xs font-semibold text-slate-700 dark:text-slate-200 transition-colors hover:bg-slate-100 dark:hover:bg-slate-700/60">
                        <img
                            src={`https://flagcdn.com/w40/${selectedCountry.code.toLowerCase()}.png`}
                            alt={selectedCountry.name}
                            className="w-4 h-3 object-cover rounded-[2px] shadow-xs border border-slate-200/60 shrink-0"
                        />
                        <span className="font-mono text-slate-800 dark:text-slate-200 font-bold">{selectedCountry.dialCode}</span>
                        <ChevronDown className="size-3 text-slate-400" />
                    </div>
                </div>

                {/* Main Phone Input */}
                <div className="relative flex-1">
                    <input
                        id={id}
                        type="tel"
                        name={name}
                        value={displayVal}
                        onChange={(e) => handleInputChange(e.target.value)}
                        onBlur={handleBlurInternal}
                        placeholder={placeholder || selectedCountry.example}
                        disabled={disabled}
                        autoComplete="tel"
                        className={`w-full h-9.5 pl-3 pr-9 rounded-r-xl border text-xs font-medium transition-all outline-none ${showInputError
                                ? 'border-red-500 focus:border-red-500 focus:ring-2 focus:ring-red-500/20 text-red-900 bg-red-50/30'
                                : showSuccess
                                    ? 'border-emerald-500 focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20 bg-emerald-50/20'
                                    : 'border-slate-200 dark:border-slate-700 focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20'
                            }`}
                    />

                    {/* Status Icon Indicator */}
                    <div className="absolute inset-y-0 right-0 pr-2.5 flex items-center pointer-events-none">
                        {showSuccess && <CheckCircle2 className="size-4 text-emerald-500 animate-in fade-in zoom-in-90" />}
                        {showInputError && <AlertCircle className="size-4 text-red-500 animate-in fade-in zoom-in-90" />}
                        {isEmpty && <Phone className="size-3.5 text-slate-400" />}
                    </div>
                </div>
            </div>

            {/* Error Message Display */}
            {showInputError && (
                <p className="text-[11px] font-medium text-red-500 animate-in fade-in slide-in-from-top-1">
                    {error || 'Nomor telepon tidak valid. Masukkan nomor telepon yang benar.'}
                </p>
            )}
        </div>
    );
}
