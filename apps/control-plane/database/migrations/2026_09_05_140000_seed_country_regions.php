<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Isi `country_regions` dengan ISO 3166-1. Alamat pos merujuk ke tabel ini lewat
 * foreign key, jadi tanpa isi tidak ada alamat yang dapat disimpan. Ditaruh di migrasi,
 * bukan seeder, karena data ini bukan contoh: ia harus ada di setiap instalasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [];
        foreach (self::COUNTRIES as $code => [$iso3, $name]) {
            $rows[] = ['code' => $code, 'iso3' => $iso3, 'name' => $name, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('country_regions')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        // Baris dipertahankan: alamat tenant mungkin sudah merujuk ke sini.
    }

    private const COUNTRIES = [
        'AD' => ['AND', 'Andorra'], 'AE' => ['ARE', 'Uni Emirat Arab'], 'AF' => ['AFG', 'Afganistan'],
        'AG' => ['ATG', 'Antigua dan Barbuda'], 'AI' => ['AIA', 'Anguilla'], 'AL' => ['ALB', 'Albania'],
        'AM' => ['ARM', 'Armenia'], 'AO' => ['AGO', 'Angola'], 'AQ' => ['ATA', 'Antarktika'],
        'AR' => ['ARG', 'Argentina'], 'AS' => ['ASM', 'Samoa Amerika'], 'AT' => ['AUT', 'Austria'],
        'AU' => ['AUS', 'Australia'], 'AW' => ['ABW', 'Aruba'], 'AX' => ['ALA', 'Kepulauan Åland'],
        'AZ' => ['AZE', 'Azerbaijan'], 'BA' => ['BIH', 'Bosnia dan Herzegovina'], 'BB' => ['BRB', 'Barbados'],
        'BD' => ['BGD', 'Bangladesh'], 'BE' => ['BEL', 'Belgia'], 'BF' => ['BFA', 'Burkina Faso'],
        'BG' => ['BGR', 'Bulgaria'], 'BH' => ['BHR', 'Bahrain'], 'BI' => ['BDI', 'Burundi'],
        'BJ' => ['BEN', 'Benin'], 'BL' => ['BLM', 'Saint Barthélemy'], 'BM' => ['BMU', 'Bermuda'],
        'BN' => ['BRN', 'Brunei Darussalam'], 'BO' => ['BOL', 'Bolivia'], 'BQ' => ['BES', 'Bonaire, Sint Eustatius, dan Saba'],
        'BR' => ['BRA', 'Brasil'], 'BS' => ['BHS', 'Bahama'], 'BT' => ['BTN', 'Bhutan'],
        'BV' => ['BVT', 'Pulau Bouvet'], 'BW' => ['BWA', 'Botswana'], 'BY' => ['BLR', 'Belarus'],
        'BZ' => ['BLZ', 'Belize'], 'CA' => ['CAN', 'Kanada'], 'CC' => ['CCK', 'Kepulauan Cocos (Keeling)'],
        'CD' => ['COD', 'Kongo (Republik Demokratik)'], 'CF' => ['CAF', 'Republik Afrika Tengah'], 'CG' => ['COG', 'Kongo'],
        'CH' => ['CHE', 'Swiss'], 'CI' => ['CIV', 'Pantai Gading'], 'CK' => ['COK', 'Kepulauan Cook'],
        'CL' => ['CHL', 'Chili'], 'CM' => ['CMR', 'Kamerun'], 'CN' => ['CHN', 'Tiongkok'],
        'CO' => ['COL', 'Kolombia'], 'CR' => ['CRI', 'Kosta Rika'], 'CU' => ['CUB', 'Kuba'],
        'CV' => ['CPV', 'Tanjung Verde'], 'CW' => ['CUW', 'Curaçao'], 'CX' => ['CXR', 'Pulau Natal'],
        'CY' => ['CYP', 'Siprus'], 'CZ' => ['CZE', 'Ceko'], 'DE' => ['DEU', 'Jerman'],
        'DJ' => ['DJI', 'Djibouti'], 'DK' => ['DNK', 'Denmark'], 'DM' => ['DMA', 'Dominika'],
        'DO' => ['DOM', 'Republik Dominika'], 'DZ' => ['DZA', 'Aljazair'], 'EC' => ['ECU', 'Ekuador'],
        'EE' => ['EST', 'Estonia'], 'EG' => ['EGY', 'Mesir'], 'EH' => ['ESH', 'Sahara Barat'],
        'ER' => ['ERI', 'Eritrea'], 'ES' => ['ESP', 'Spanyol'], 'ET' => ['ETH', 'Etiopia'],
        'FI' => ['FIN', 'Finlandia'], 'FJ' => ['FJI', 'Fiji'], 'FK' => ['FLK', 'Kepulauan Falkland'],
        'FM' => ['FSM', 'Mikronesia'], 'FO' => ['FRO', 'Kepulauan Faroe'], 'FR' => ['FRA', 'Prancis'],
        'GA' => ['GAB', 'Gabon'], 'GB' => ['GBR', 'Britania Raya'], 'GD' => ['GRD', 'Grenada'],
        'GE' => ['GEO', 'Georgia'], 'GF' => ['GUF', 'Guyana Prancis'], 'GG' => ['GGY', 'Guernsey'],
        'GH' => ['GHA', 'Ghana'], 'GI' => ['GIB', 'Gibraltar'], 'GL' => ['GRL', 'Greenland'],
        'GM' => ['GMB', 'Gambia'], 'GN' => ['GIN', 'Guinea'], 'GP' => ['GLP', 'Guadeloupe'],
        'GQ' => ['GNQ', 'Guinea Ekuatorial'], 'GR' => ['GRC', 'Yunani'], 'GS' => ['SGS', 'Georgia Selatan dan Kepulauan Sandwich Selatan'],
        'GT' => ['GTM', 'Guatemala'], 'GU' => ['GUM', 'Guam'], 'GW' => ['GNB', 'Guinea-Bissau'],
        'GY' => ['GUY', 'Guyana'], 'HK' => ['HKG', 'Hong Kong'], 'HM' => ['HMD', 'Pulau Heard dan Kepulauan McDonald'],
        'HN' => ['HND', 'Honduras'], 'HR' => ['HRV', 'Kroasia'], 'HT' => ['HTI', 'Haiti'],
        'HU' => ['HUN', 'Hungaria'], 'ID' => ['IDN', 'Indonesia'], 'IE' => ['IRL', 'Irlandia'],
        'IL' => ['ISR', 'Israel'], 'IM' => ['IMN', 'Pulau Man'], 'IN' => ['IND', 'India'],
        'IO' => ['IOT', 'Wilayah Samudra Hindia Britania'], 'IQ' => ['IRQ', 'Irak'], 'IR' => ['IRN', 'Iran'],
        'IS' => ['ISL', 'Islandia'], 'IT' => ['ITA', 'Italia'], 'JE' => ['JEY', 'Jersey'],
        'JM' => ['JAM', 'Jamaika'], 'JO' => ['JOR', 'Yordania'], 'JP' => ['JPN', 'Jepang'],
        'KE' => ['KEN', 'Kenya'], 'KG' => ['KGZ', 'Kirgizstan'], 'KH' => ['KHM', 'Kamboja'],
        'KI' => ['KIR', 'Kiribati'], 'KM' => ['COM', 'Komoro'], 'KN' => ['KNA', 'Saint Kitts dan Nevis'],
        'KP' => ['PRK', 'Korea Utara'], 'KR' => ['KOR', 'Korea Selatan'], 'KW' => ['KWT', 'Kuwait'],
        'KY' => ['CYM', 'Kepulauan Cayman'], 'KZ' => ['KAZ', 'Kazakhstan'], 'LA' => ['LAO', 'Laos'],
        'LB' => ['LBN', 'Lebanon'], 'LC' => ['LCA', 'Saint Lucia'], 'LI' => ['LIE', 'Liechtenstein'],
        'LK' => ['LKA', 'Sri Lanka'], 'LR' => ['LBR', 'Liberia'], 'LS' => ['LSO', 'Lesotho'],
        'LT' => ['LTU', 'Lituania'], 'LU' => ['LUX', 'Luksemburg'], 'LV' => ['LVA', 'Latvia'],
        'LY' => ['LBY', 'Libya'], 'MA' => ['MAR', 'Maroko'], 'MC' => ['MCO', 'Monako'],
        'MD' => ['MDA', 'Moldova'], 'ME' => ['MNE', 'Montenegro'], 'MF' => ['MAF', 'Saint Martin'],
        'MG' => ['MDG', 'Madagaskar'], 'MH' => ['MHL', 'Kepulauan Marshall'], 'MK' => ['MKD', 'Makedonia Utara'],
        'ML' => ['MLI', 'Mali'], 'MM' => ['MMR', 'Myanmar'], 'MN' => ['MNG', 'Mongolia'],
        'MO' => ['MAC', 'Makau'], 'MP' => ['MNP', 'Kepulauan Mariana Utara'], 'MQ' => ['MTQ', 'Martinique'],
        'MR' => ['MRT', 'Mauritania'], 'MS' => ['MSR', 'Montserrat'], 'MT' => ['MLT', 'Malta'],
        'MU' => ['MUS', 'Mauritius'], 'MV' => ['MDV', 'Maladewa'], 'MW' => ['MWI', 'Malawi'],
        'MX' => ['MEX', 'Meksiko'], 'MY' => ['MYS', 'Malaysia'], 'MZ' => ['MOZ', 'Mozambik'],
        'NA' => ['NAM', 'Namibia'], 'NC' => ['NCL', 'Kaledonia Baru'], 'NE' => ['NER', 'Niger'],
        'NF' => ['NFK', 'Pulau Norfolk'], 'NG' => ['NGA', 'Nigeria'], 'NI' => ['NIC', 'Nikaragua'],
        'NL' => ['NLD', 'Belanda'], 'NO' => ['NOR', 'Norwegia'], 'NP' => ['NPL', 'Nepal'],
        'NR' => ['NRU', 'Nauru'], 'NU' => ['NIU', 'Niue'], 'NZ' => ['NZL', 'Selandia Baru'],
        'OM' => ['OMN', 'Oman'], 'PA' => ['PAN', 'Panama'], 'PE' => ['PER', 'Peru'],
        'PF' => ['PYF', 'Polinesia Prancis'], 'PG' => ['PNG', 'Papua Nugini'], 'PH' => ['PHL', 'Filipina'],
        'PK' => ['PAK', 'Pakistan'], 'PL' => ['POL', 'Polandia'], 'PM' => ['SPM', 'Saint Pierre dan Miquelon'],
        'PN' => ['PCN', 'Kepulauan Pitcairn'], 'PR' => ['PRI', 'Puerto Riko'], 'PS' => ['PSE', 'Palestina'],
        'PT' => ['PRT', 'Portugal'], 'PW' => ['PLW', 'Palau'], 'PY' => ['PRY', 'Paraguay'],
        'QA' => ['QAT', 'Qatar'], 'RE' => ['REU', 'Réunion'], 'RO' => ['ROU', 'Rumania'],
        'RS' => ['SRB', 'Serbia'], 'RU' => ['RUS', 'Rusia'], 'RW' => ['RWA', 'Rwanda'],
        'SA' => ['SAU', 'Arab Saudi'], 'SB' => ['SLB', 'Kepulauan Solomon'], 'SC' => ['SYC', 'Seychelles'],
        'SD' => ['SDN', 'Sudan'], 'SE' => ['SWE', 'Swedia'], 'SG' => ['SGP', 'Singapura'],
        'SH' => ['SHN', 'Saint Helena'], 'SI' => ['SVN', 'Slovenia'], 'SJ' => ['SJM', 'Svalbard dan Jan Mayen'],
        'SK' => ['SVK', 'Slowakia'], 'SL' => ['SLE', 'Sierra Leone'], 'SM' => ['SMR', 'San Marino'],
        'SN' => ['SEN', 'Senegal'], 'SO' => ['SOM', 'Somalia'], 'SR' => ['SUR', 'Suriname'],
        'SS' => ['SSD', 'Sudan Selatan'], 'ST' => ['STP', 'Sao Tome dan Principe'], 'SV' => ['SLV', 'El Salvador'],
        'SX' => ['SXM', 'Sint Maarten'], 'SY' => ['SYR', 'Suriah'], 'SZ' => ['SWZ', 'Eswatini'],
        'TC' => ['TCA', 'Kepulauan Turks dan Caicos'], 'TD' => ['TCD', 'Chad'], 'TF' => ['ATF', 'Wilayah Selatan Prancis'],
        'TG' => ['TGO', 'Togo'], 'TH' => ['THA', 'Thailand'], 'TJ' => ['TJK', 'Tajikistan'],
        'TK' => ['TKL', 'Tokelau'], 'TL' => ['TLS', 'Timor-Leste'], 'TM' => ['TKM', 'Turkmenistan'],
        'TN' => ['TUN', 'Tunisia'], 'TO' => ['TON', 'Tonga'], 'TR' => ['TUR', 'Turki'],
        'TT' => ['TTO', 'Trinidad dan Tobago'], 'TV' => ['TUV', 'Tuvalu'], 'TW' => ['TWN', 'Taiwan'],
        'TZ' => ['TZA', 'Tanzania'], 'UA' => ['UKR', 'Ukraina'], 'UG' => ['UGA', 'Uganda'],
        'UM' => ['UMI', 'Kepulauan Terluar Kecil Amerika Serikat'], 'US' => ['USA', 'Amerika Serikat'], 'UY' => ['URY', 'Uruguay'],
        'UZ' => ['UZB', 'Uzbekistan'], 'VA' => ['VAT', 'Vatikan'], 'VC' => ['VCT', 'Saint Vincent dan Grenadines'],
        'VE' => ['VEN', 'Venezuela'], 'VG' => ['VGB', 'Kepulauan Virgin Britania'], 'VI' => ['VIR', 'Kepulauan Virgin Amerika'],
        'VN' => ['VNM', 'Vietnam'], 'VU' => ['VUT', 'Vanuatu'], 'WF' => ['WLF', 'Wallis dan Futuna'],
        'WS' => ['WSM', 'Samoa'], 'YE' => ['YEM', 'Yaman'], 'YT' => ['MYT', 'Mayotte'],
        'ZA' => ['ZAF', 'Afrika Selatan'], 'ZM' => ['ZMB', 'Zambia'], 'ZW' => ['ZWE', 'Zimbabwe'],
    ];
};
