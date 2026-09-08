<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorldCountriesSeeder extends Seeder
{
    /**
     * Complete ISO 3166-1 countries and official territories with
     * Alpha-2 code, Alpha-3 ISO code, International Phone Calling Code, and Primary IANA Timezone.
     */
    public const COUNTRIES = [
        ['code' => 'AD', 'iso3' => 'AND', 'name' => 'Andorra', 'phone_code' => '+376', 'timezone' => 'Europe/Andorra'],
        ['code' => 'AE', 'iso3' => 'ARE', 'name' => 'United Arab Emirates', 'phone_code' => '+971', 'timezone' => 'Asia/Dubai'],
        ['code' => 'AF', 'iso3' => 'AFG', 'name' => 'Afghanistan', 'phone_code' => '+93', 'timezone' => 'Asia/Kabul'],
        ['code' => 'AG', 'iso3' => 'ATG', 'name' => 'Antigua and Barbuda', 'phone_code' => '+1-268', 'timezone' => 'America/Antigua'],
        ['code' => 'AI', 'iso3' => 'AIA', 'name' => 'Anguilla', 'phone_code' => '+1-264', 'timezone' => 'America/Anguilla'],
        ['code' => 'AL', 'iso3' => 'ALB', 'name' => 'Albania', 'phone_code' => '+355', 'timezone' => 'Europe/Tirane'],
        ['code' => 'AM', 'iso3' => 'ARM', 'name' => 'Armenia', 'phone_code' => '+374', 'timezone' => 'Asia/Yerevan'],
        ['code' => 'AO', 'iso3' => 'AGO', 'name' => 'Angola', 'phone_code' => '+244', 'timezone' => 'Africa/Luanda'],
        ['code' => 'AQ', 'iso3' => 'ATA', 'name' => 'Antarctica', 'phone_code' => '+672', 'timezone' => 'Antarctica/McMurdo'],
        ['code' => 'AR', 'iso3' => 'ARG', 'name' => 'Argentina', 'phone_code' => '+54', 'timezone' => 'America/Argentina/Buenos_Aires'],
        ['code' => 'AS', 'iso3' => 'ASM', 'name' => 'American Samoa', 'phone_code' => '+1-684', 'timezone' => 'Pacific/Pago_Pago'],
        ['code' => 'AT', 'iso3' => 'AUT', 'name' => 'Austria', 'phone_code' => '+43', 'timezone' => 'Europe/Vienna'],
        ['code' => 'AU', 'iso3' => 'AUS', 'name' => 'Australia', 'phone_code' => '+61', 'timezone' => 'Australia/Sydney'],
        ['code' => 'AW', 'iso3' => 'ABW', 'name' => 'Aruba', 'phone_code' => '+297', 'timezone' => 'America/Aruba'],
        ['code' => 'AX', 'iso3' => 'ALA', 'name' => 'Åland Islands', 'phone_code' => '+358', 'timezone' => 'Europe/Mariehamn'],
        ['code' => 'AZ', 'iso3' => 'AZE', 'name' => 'Azerbaijan', 'phone_code' => '+994', 'timezone' => 'Asia/Baku'],
        ['code' => 'BA', 'iso3' => 'BIH', 'name' => 'Bosnia and Herzegovina', 'phone_code' => '+387', 'timezone' => 'Europe/Sarajevo'],
        ['code' => 'BB', 'iso3' => 'BRB', 'name' => 'Barbados', 'phone_code' => '+1-246', 'timezone' => 'America/Barbados'],
        ['code' => 'BD', 'iso3' => 'BGD', 'name' => 'Bangladesh', 'phone_code' => '+880', 'timezone' => 'Asia/Dhaka'],
        ['code' => 'BE', 'iso3' => 'BEL', 'name' => 'Belgium', 'phone_code' => '+32', 'timezone' => 'Europe/Brussels'],
        ['code' => 'BF', 'iso3' => 'BFA', 'name' => 'Burkina Faso', 'phone_code' => '+226', 'timezone' => 'Africa/Ouagadougou'],
        ['code' => 'BG', 'iso3' => 'BGR', 'name' => 'Bulgaria', 'phone_code' => '+359', 'timezone' => 'Europe/Sofia'],
        ['code' => 'BH', 'iso3' => 'BHR', 'name' => 'Bahrain', 'phone_code' => '+973', 'timezone' => 'Asia/Bahrain'],
        ['code' => 'BI', 'iso3' => 'BDI', 'name' => 'Burundi', 'phone_code' => '+257', 'timezone' => 'Africa/Bujumbura'],
        ['code' => 'BJ', 'iso3' => 'BEN', 'name' => 'Benin', 'phone_code' => '+229', 'timezone' => 'Africa/Porto-Novo'],
        ['code' => 'BL', 'iso3' => 'BLM', 'name' => 'Saint Barthélemy', 'phone_code' => '+590', 'timezone' => 'America/St_Barthelemy'],
        ['code' => 'BM', 'iso3' => 'BMU', 'name' => 'Bermuda', 'phone_code' => '+1-441', 'timezone' => 'Atlantic/Bermuda'],
        ['code' => 'BN', 'iso3' => 'BRN', 'name' => 'Brunei Darussalam', 'phone_code' => '+673', 'timezone' => 'Asia/Brunei'],
        ['code' => 'BO', 'iso3' => 'BOL', 'name' => 'Bolivia', 'phone_code' => '+591', 'timezone' => 'America/La_Paz'],
        ['code' => 'BQ', 'iso3' => 'BES', 'name' => 'Bonaire, Sint Eustatius and Saba', 'phone_code' => '+599', 'timezone' => 'America/Kralendijk'],
        ['code' => 'BR', 'iso3' => 'BRA', 'name' => 'Brazil', 'phone_code' => '+55', 'timezone' => 'America/Sao_Paulo'],
        ['code' => 'BS', 'iso3' => 'BHS', 'name' => 'Bahamas', 'phone_code' => '+1-242', 'timezone' => 'America/Nassau'],
        ['code' => 'BT', 'iso3' => 'BTN', 'name' => 'Bhutan', 'phone_code' => '+975', 'timezone' => 'Asia/Thimphu'],
        ['code' => 'BV', 'iso3' => 'BVT', 'name' => 'Bouvet Island', 'phone_code' => '+47', 'timezone' => 'UTC'],
        ['code' => 'BW', 'iso3' => 'BWA', 'name' => 'Botswana', 'phone_code' => '+267', 'timezone' => 'Africa/Gaborone'],
        ['code' => 'BY', 'iso3' => 'BLR', 'name' => 'Belarus', 'phone_code' => '+375', 'timezone' => 'Europe/Minsk'],
        ['code' => 'BZ', 'iso3' => 'BLZ', 'name' => 'Belize', 'phone_code' => '+501', 'timezone' => 'America/Belize'],
        ['code' => 'CA', 'iso3' => 'CAN', 'name' => 'Canada', 'phone_code' => '+1', 'timezone' => 'America/Toronto'],
        ['code' => 'CC', 'iso3' => 'CCK', 'name' => 'Cocos (Keeling) Islands', 'phone_code' => '+61', 'timezone' => 'Indian/Cocos'],
        ['code' => 'CD', 'iso3' => 'COD', 'name' => 'Congo (DRC)', 'phone_code' => '+243', 'timezone' => 'Africa/Kinshasa'],
        ['code' => 'CF', 'iso3' => 'CAF', 'name' => 'Central African Republic', 'phone_code' => '+236', 'timezone' => 'Africa/Bangui'],
        ['code' => 'CG', 'iso3' => 'COG', 'name' => 'Congo (Republic)', 'phone_code' => '+242', 'timezone' => 'Africa/Brazzaville'],
        ['code' => 'CH', 'iso3' => 'CHE', 'name' => 'Switzerland', 'phone_code' => '+41', 'timezone' => 'Europe/Zurich'],
        ['code' => 'CI', 'iso3' => 'CIV', 'name' => "Côte d'Ivoire", 'phone_code' => '+225', 'timezone' => 'Africa/Abidjan'],
        ['code' => 'CK', 'iso3' => 'COK', 'name' => 'Cook Islands', 'phone_code' => '+682', 'timezone' => 'Pacific/Rarotonga'],
        ['code' => 'CL', 'iso3' => 'CHL', 'name' => 'Chile', 'phone_code' => '+56', 'timezone' => 'America/Santiago'],
        ['code' => 'CM', 'iso3' => 'CMR', 'name' => 'Cameroon', 'phone_code' => '+237', 'timezone' => 'Africa/Douala'],
        ['code' => 'CN', 'iso3' => 'CHN', 'name' => 'China', 'phone_code' => '+86', 'timezone' => 'Asia/Shanghai'],
        ['code' => 'CO', 'iso3' => 'COL', 'name' => 'Colombia', 'phone_code' => '+57', 'timezone' => 'America/Bogota'],
        ['code' => 'CR', 'iso3' => 'CRI', 'name' => 'Costa Rica', 'phone_code' => '+506', 'timezone' => 'America/Costa_Rica'],
        ['code' => 'CU', 'iso3' => 'CUB', 'name' => 'Cuba', 'phone_code' => '+53', 'timezone' => 'America/Havana'],
        ['code' => 'CV', 'iso3' => 'CPV', 'name' => 'Cape Verde', 'phone_code' => '+238', 'timezone' => 'Atlantic/Cape_Verde'],
        ['code' => 'CW', 'iso3' => 'CUW', 'name' => 'Curaçao', 'phone_code' => '+599', 'timezone' => 'America/Curacao'],
        ['code' => 'CX', 'iso3' => 'CXR', 'name' => 'Christmas Island', 'phone_code' => '+61', 'timezone' => 'Indian/Christmas'],
        ['code' => 'CY', 'iso3' => 'CYP', 'name' => 'Cyprus', 'phone_code' => '+357', 'timezone' => 'Asia/Nicosia'],
        ['code' => 'CZ', 'iso3' => 'CZE', 'name' => 'Czech Republic', 'phone_code' => '+420', 'timezone' => 'Europe/Prague'],
        ['code' => 'DE', 'iso3' => 'DEU', 'name' => 'Germany', 'phone_code' => '+49', 'timezone' => 'Europe/Berlin'],
        ['code' => 'DJ', 'iso3' => 'DJI', 'name' => 'Djibouti', 'phone_code' => '+253', 'timezone' => 'Africa/Djibouti'],
        ['code' => 'DK', 'iso3' => 'DNK', 'name' => 'Denmark', 'phone_code' => '+45', 'timezone' => 'Europe/Copenhagen'],
        ['code' => 'DM', 'iso3' => 'DMA', 'name' => 'Dominica', 'phone_code' => '+1-767', 'timezone' => 'America/Dominica'],
        ['code' => 'DO', 'iso3' => 'DOM', 'name' => 'Dominican Republic', 'phone_code' => '+1-809', 'timezone' => 'America/Santo_Domingo'],
        ['code' => 'DZ', 'iso3' => 'DZA', 'name' => 'Algeria', 'phone_code' => '+213', 'timezone' => 'Africa/Algiers'],
        ['code' => 'EC', 'iso3' => 'ECU', 'name' => 'Ecuador', 'phone_code' => '+593', 'timezone' => 'America/Guayaquil'],
        ['code' => 'EE', 'iso3' => 'EST', 'name' => 'Estonia', 'phone_code' => '+372', 'timezone' => 'Europe/Tallinn'],
        ['code' => 'EG', 'iso3' => 'EGY', 'name' => 'Egypt', 'phone_code' => '+20', 'timezone' => 'Africa/Cairo'],
        ['code' => 'EH', 'iso3' => 'ESH', 'name' => 'Western Sahara', 'phone_code' => '+212', 'timezone' => 'Africa/El_Aaiun'],
        ['code' => 'ER', 'iso3' => 'ERI', 'name' => 'Eritrea', 'phone_code' => '+291', 'timezone' => 'Africa/Asmara'],
        ['code' => 'ES', 'iso3' => 'ESP', 'name' => 'Spain', 'phone_code' => '+34', 'timezone' => 'Europe/Madrid'],
        ['code' => 'ET', 'iso3' => 'ETH', 'name' => 'Ethiopia', 'phone_code' => '+251', 'timezone' => 'Africa/Addis_Ababa'],
        ['code' => 'FI', 'iso3' => 'FIN', 'name' => 'Finland', 'phone_code' => '+358', 'timezone' => 'Europe/Helsinki'],
        ['code' => 'FJ', 'iso3' => 'FJI', 'name' => 'Fiji', 'phone_code' => '+679', 'timezone' => 'Pacific/Fiji'],
        ['code' => 'FK', 'iso3' => 'FLK', 'name' => 'Falkland Islands', 'phone_code' => '+500', 'timezone' => 'Atlantic/Stanley'],
        ['code' => 'FM', 'iso3' => 'FSM', 'name' => 'Micronesia', 'phone_code' => '+691', 'timezone' => 'Pacific/Pohnpei'],
        ['code' => 'FO', 'iso3' => 'FRO', 'name' => 'Faroe Islands', 'phone_code' => '+298', 'timezone' => 'Atlantic/Faroe'],
        ['code' => 'FR', 'iso3' => 'FRA', 'name' => 'France', 'phone_code' => '+33', 'timezone' => 'Europe/Paris'],
        ['code' => 'GA', 'iso3' => 'GAB', 'name' => 'Gabon', 'phone_code' => '+241', 'timezone' => 'Africa/Libreville'],
        ['code' => 'GB', 'iso3' => 'GBR', 'name' => 'United Kingdom', 'phone_code' => '+44', 'timezone' => 'Europe/London'],
        ['code' => 'GD', 'iso3' => 'GRD', 'name' => 'Grenada', 'phone_code' => '+1-473', 'timezone' => 'America/Grenada'],
        ['code' => 'GE', 'iso3' => 'GEO', 'name' => 'Georgia', 'phone_code' => '+995', 'timezone' => 'Asia/Tbilisi'],
        ['code' => 'GF', 'iso3' => 'GUF', 'name' => 'French Guiana', 'phone_code' => '+594', 'timezone' => 'America/Cayenne'],
        ['code' => 'GG', 'iso3' => 'GGY', 'name' => 'Guernsey', 'phone_code' => '+44', 'timezone' => 'Europe/Guernsey'],
        ['code' => 'GH', 'iso3' => 'GHA', 'name' => 'Ghana', 'phone_code' => '+233', 'timezone' => 'Africa/Accra'],
        ['code' => 'GI', 'iso3' => 'GIB', 'name' => 'Gibraltar', 'phone_code' => '+350', 'timezone' => 'Europe/Gibraltar'],
        ['code' => 'GL', 'iso3' => 'GRL', 'name' => 'Greenland', 'phone_code' => '+299', 'timezone' => 'America/Nuuk'],
        ['code' => 'GM', 'iso3' => 'GMB', 'name' => 'Gambia', 'phone_code' => '+220', 'timezone' => 'Africa/Banjul'],
        ['code' => 'GN', 'iso3' => 'GIN', 'name' => 'Guinea', 'phone_code' => '+224', 'timezone' => 'Africa/Conakry'],
        ['code' => 'GP', 'iso3' => 'GLP', 'name' => 'Guadeloupe', 'phone_code' => '+590', 'timezone' => 'America/Guadeloupe'],
        ['code' => 'GQ', 'iso3' => 'GNQ', 'name' => 'Equatorial Guinea', 'phone_code' => '+240', 'timezone' => 'Africa/Malabo'],
        ['code' => 'GR', 'iso3' => 'GRC', 'name' => 'Greece', 'phone_code' => '+30', 'timezone' => 'Europe/Athens'],
        ['code' => 'GS', 'iso3' => 'SGS', 'name' => 'South Georgia and South Sandwich Islands', 'phone_code' => '+500', 'timezone' => 'Atlantic/South_Georgia'],
        ['code' => 'GT', 'iso3' => 'GTM', 'name' => 'Guatemala', 'phone_code' => '+502', 'timezone' => 'America/Guatemala'],
        ['code' => 'GU', 'iso3' => 'GUM', 'name' => 'Guam', 'phone_code' => '+1-671', 'timezone' => 'Pacific/Guam'],
        ['code' => 'GW', 'iso3' => 'GNB', 'name' => 'Guinea-Bissau', 'phone_code' => '+245', 'timezone' => 'Africa/Bissau'],
        ['code' => 'GY', 'iso3' => 'GUY', 'name' => 'Guyana', 'phone_code' => '+592', 'timezone' => 'America/Guyana'],
        ['code' => 'HK', 'iso3' => 'HKG', 'name' => 'Hong Kong', 'phone_code' => '+852', 'timezone' => 'Asia/Hong_Kong'],
        ['code' => 'HM', 'iso3' => 'HMD', 'name' => 'Heard Island and McDonald Islands', 'phone_code' => '+672', 'timezone' => 'UTC'],
        ['code' => 'HN', 'iso3' => 'HND', 'name' => 'Honduras', 'phone_code' => '+504', 'timezone' => 'America/Tegucigalpa'],
        ['code' => 'HR', 'iso3' => 'HRV', 'name' => 'Croatia', 'phone_code' => '+385', 'timezone' => 'Europe/Zagreb'],
        ['code' => 'HT', 'iso3' => 'HTI', 'name' => 'Haiti', 'phone_code' => '+509', 'timezone' => 'America/Port-au-Prince'],
        ['code' => 'HU', 'iso3' => 'HUN', 'name' => 'Hungary', 'phone_code' => '+36', 'timezone' => 'Europe/Budapest'],
        ['code' => 'ID', 'iso3' => 'IDN', 'name' => 'Indonesia', 'phone_code' => '+62', 'timezone' => 'Asia/Jakarta'],
        ['code' => 'IE', 'iso3' => 'IRL', 'name' => 'Ireland', 'phone_code' => '+353', 'timezone' => 'Europe/Dublin'],
        ['code' => 'IL', 'iso3' => 'ISR', 'name' => 'Israel', 'phone_code' => '+972', 'timezone' => 'Asia/Jerusalem'],
        ['code' => 'IM', 'iso3' => 'IMN', 'name' => 'Isle of Man', 'phone_code' => '+44', 'timezone' => 'Europe/Isle_of_Man'],
        ['code' => 'IN', 'iso3' => 'IND', 'name' => 'India', 'phone_code' => '+91', 'timezone' => 'Asia/Kolkata'],
        ['code' => 'IO', 'iso3' => 'IOT', 'name' => 'British Indian Ocean Territory', 'phone_code' => '+246', 'timezone' => 'Indian/Chagos'],
        ['code' => 'IQ', 'iso3' => 'IRQ', 'name' => 'Iraq', 'phone_code' => '+964', 'timezone' => 'Asia/Baghdad'],
        ['code' => 'IR', 'iso3' => 'IRN', 'name' => 'Iran', 'phone_code' => '+98', 'timezone' => 'Asia/Tehran'],
        ['code' => 'IS', 'iso3' => 'ISL', 'name' => 'Iceland', 'phone_code' => '+354', 'timezone' => 'Atlantic/Reykjavik'],
        ['code' => 'IT', 'iso3' => 'ITA', 'name' => 'Italy', 'phone_code' => '+39', 'timezone' => 'Europe/Rome'],
        ['code' => 'JE', 'iso3' => 'JEY', 'name' => 'Jersey', 'phone_code' => '+44', 'timezone' => 'Europe/Jersey'],
        ['code' => 'JM', 'iso3' => 'JAM', 'name' => 'Jamaica', 'phone_code' => '+1-876', 'timezone' => 'America/Jamaica'],
        ['code' => 'JO', 'iso3' => 'JOR', 'name' => 'Jordan', 'phone_code' => '+962', 'timezone' => 'Asia/Amman'],
        ['code' => 'JP', 'iso3' => 'JPN', 'name' => 'Japan', 'phone_code' => '+81', 'timezone' => 'Asia/Tokyo'],
        ['code' => 'KE', 'iso3' => 'KEN', 'name' => 'Kenya', 'phone_code' => '+254', 'timezone' => 'Africa/Nairobi'],
        ['code' => 'KG', 'iso3' => 'KGZ', 'name' => 'Kyrgyzstan', 'phone_code' => '+996', 'timezone' => 'Asia/Bishkek'],
        ['code' => 'KH', 'iso3' => 'KHM', 'name' => 'Cambodia', 'phone_code' => '+855', 'timezone' => 'Asia/Phnom_Penh'],
        ['code' => 'KI', 'iso3' => 'KIR', 'name' => 'Kiribati', 'phone_code' => '+686', 'timezone' => 'Pacific/Tarawa'],
        ['code' => 'KM', 'iso3' => 'COM', 'name' => 'Comoros', 'phone_code' => '+269', 'timezone' => 'Indian/Comoro'],
        ['code' => 'KN', 'iso3' => 'KNA', 'name' => 'Saint Kitts and Nevis', 'phone_code' => '+1-869', 'timezone' => 'America/St_Kitts'],
        ['code' => 'KP', 'iso3' => 'PRK', 'name' => 'North Korea', 'phone_code' => '+850', 'timezone' => 'Asia/Pyongyang'],
        ['code' => 'KR', 'iso3' => 'KOR', 'name' => 'South Korea', 'phone_code' => '+82', 'timezone' => 'Asia/Seoul'],
        ['code' => 'KW', 'iso3' => 'KWT', 'name' => 'Kuwait', 'phone_code' => '+965', 'timezone' => 'Asia/Kuwait'],
        ['code' => 'KY', 'iso3' => 'CYM', 'name' => 'Cayman Islands', 'phone_code' => '+1-345', 'timezone' => 'America/Cayman'],
        ['code' => 'KZ', 'iso3' => 'KAZ', 'name' => 'Kazakhstan', 'phone_code' => '+7', 'timezone' => 'Asia/Almaty'],
        ['code' => 'LA', 'iso3' => 'LAO', 'name' => 'Laos', 'phone_code' => '+856', 'timezone' => 'Asia/Vientiane'],
        ['code' => 'LB', 'iso3' => 'LBN', 'name' => 'Lebanon', 'phone_code' => '+961', 'timezone' => 'Asia/Beirut'],
        ['code' => 'LC', 'iso3' => 'LCA', 'name' => 'Saint Lucia', 'phone_code' => '+1-758', 'timezone' => 'America/St_Lucia'],
        ['code' => 'LI', 'iso3' => 'LIE', 'name' => 'Liechtenstein', 'phone_code' => '+423', 'timezone' => 'Europe/Vaduz'],
        ['code' => 'LK', 'iso3' => 'LKA', 'name' => 'Sri Lanka', 'phone_code' => '+94', 'timezone' => 'Asia/Colombo'],
        ['code' => 'LR', 'iso3' => 'LBR', 'name' => 'Liberia', 'phone_code' => '+231', 'timezone' => 'Africa/Monrovia'],
        ['code' => 'LS', 'iso3' => 'LSO', 'name' => 'Lesotho', 'phone_code' => '+266', 'timezone' => 'Africa/Maseru'],
        ['code' => 'LT', 'iso3' => 'LTU', 'name' => 'Lithuania', 'phone_code' => '+370', 'timezone' => 'Europe/Vilnius'],
        ['code' => 'LU', 'iso3' => 'LUX', 'name' => 'Luxembourg', 'phone_code' => '+352', 'timezone' => 'Europe/Luxembourg'],
        ['code' => 'LV', 'iso3' => 'LVA', 'name' => 'Latvia', 'phone_code' => '+371', 'timezone' => 'Europe/Riga'],
        ['code' => 'LY', 'iso3' => 'LBY', 'name' => 'Libya', 'phone_code' => '+218', 'timezone' => 'Africa/Tripoli'],
        ['code' => 'MA', 'iso3' => 'MAR', 'name' => 'Morocco', 'phone_code' => '+212', 'timezone' => 'Africa/Casablanca'],
        ['code' => 'MC', 'iso3' => 'MCO', 'name' => 'Monaco', 'phone_code' => '+377', 'timezone' => 'Europe/Monaco'],
        ['code' => 'MD', 'iso3' => 'MDA', 'name' => 'Moldova', 'phone_code' => '+373', 'timezone' => 'Europe/Chisinau'],
        ['code' => 'ME', 'iso3' => 'MNE', 'name' => 'Montenegro', 'phone_code' => '+382', 'timezone' => 'Europe/Podgorica'],
        ['code' => 'MF', 'iso3' => 'MAF', 'name' => 'Saint Martin (French part)', 'phone_code' => '+590', 'timezone' => 'America/Marigot'],
        ['code' => 'MG', 'iso3' => 'MDG', 'name' => 'Madagascar', 'phone_code' => '+261', 'timezone' => 'Indian/Antananarivo'],
        ['code' => 'MH', 'iso3' => 'MHL', 'name' => 'Marshall Islands', 'phone_code' => '+692', 'timezone' => 'Pacific/Majuro'],
        ['code' => 'MK', 'iso3' => 'MKD', 'name' => 'North Macedonia', 'phone_code' => '+389', 'timezone' => 'Europe/Skopje'],
        ['code' => 'ML', 'iso3' => 'MLI', 'name' => 'Mali', 'phone_code' => '+223', 'timezone' => 'Africa/Bamako'],
        ['code' => 'MM', 'iso3' => 'MMR', 'name' => 'Myanmar', 'phone_code' => '+95', 'timezone' => 'Asia/Yangon'],
        ['code' => 'MN', 'iso3' => 'MNG', 'name' => 'Mongolia', 'phone_code' => '+976', 'timezone' => 'Asia/Ulaanbaatar'],
        ['code' => 'MO', 'iso3' => 'MAC', 'name' => 'Macau', 'phone_code' => '+853', 'timezone' => 'Asia/Macau'],
        ['code' => 'MP', 'iso3' => 'MNP', 'name' => 'Northern Mariana Islands', 'phone_code' => '+1-670', 'timezone' => 'Pacific/Saipan'],
        ['code' => 'MQ', 'iso3' => 'MTQ', 'name' => 'Martinique', 'phone_code' => '+596', 'timezone' => 'America/Martinique'],
        ['code' => 'MR', 'iso3' => 'MRT', 'name' => 'Mauritania', 'phone_code' => '+222', 'timezone' => 'Africa/Nouakchott'],
        ['code' => 'MS', 'iso3' => 'MSR', 'name' => 'Montserrat', 'phone_code' => '+1-664', 'timezone' => 'America/Montserrat'],
        ['code' => 'MT', 'iso3' => 'MLT', 'name' => 'Malta', 'phone_code' => '+356', 'timezone' => 'Europe/Malta'],
        ['code' => 'MU', 'iso3' => 'MUS', 'name' => 'Mauritius', 'phone_code' => '+230', 'timezone' => 'Indian/Mauritius'],
        ['code' => 'MV', 'iso3' => 'MDV', 'name' => 'Maldives', 'phone_code' => '+960', 'timezone' => 'Indian/Maldives'],
        ['code' => 'MW', 'iso3' => 'MWI', 'name' => 'Malawi', 'phone_code' => '+265', 'timezone' => 'Africa/Blantyre'],
        ['code' => 'MX', 'iso3' => 'MEX', 'name' => 'Mexico', 'phone_code' => '+52', 'timezone' => 'America/Mexico_City'],
        ['code' => 'MY', 'iso3' => 'MYS', 'name' => 'Malaysia', 'phone_code' => '+60', 'timezone' => 'Asia/Kuala_Lumpur'],
        ['code' => 'MZ', 'iso3' => 'MOZ', 'name' => 'Mozambique', 'phone_code' => '+258', 'timezone' => 'Africa/Maputo'],
        ['code' => 'NA', 'iso3' => 'NAM', 'name' => 'Namibia', 'phone_code' => '+264', 'timezone' => 'Africa/Windhoek'],
        ['code' => 'NC', 'iso3' => 'NCL', 'name' => 'New Caledonia', 'phone_code' => '+687', 'timezone' => 'Pacific/Noumea'],
        ['code' => 'NE', 'iso3' => 'NER', 'name' => 'Niger', 'phone_code' => '+227', 'timezone' => 'Africa/Niamey'],
        ['code' => 'NF', 'iso3' => 'NFK', 'name' => 'Norfolk Island', 'phone_code' => '+672', 'timezone' => 'Pacific/Norfolk'],
        ['code' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '+234', 'timezone' => 'Africa/Lagos'],
        ['code' => 'NI', 'iso3' => 'NIC', 'name' => 'Nicaragua', 'phone_code' => '+505', 'timezone' => 'America/Managua'],
        ['code' => 'NL', 'iso3' => 'NLD', 'name' => 'Netherlands', 'phone_code' => '+31', 'timezone' => 'Europe/Amsterdam'],
        ['code' => 'NO', 'iso3' => 'NOR', 'name' => 'Norway', 'phone_code' => '+47', 'timezone' => 'Europe/Oslo'],
        ['code' => 'NP', 'iso3' => 'NPL', 'name' => 'Nepal', 'phone_code' => '+977', 'timezone' => 'Asia/Kathmandu'],
        ['code' => 'NR', 'iso3' => 'NRU', 'name' => 'Nauru', 'phone_code' => '+674', 'timezone' => 'Pacific/Nauru'],
        ['code' => 'NU', 'iso3' => 'NIU', 'name' => 'Niue', 'phone_code' => '+683', 'timezone' => 'Pacific/Niue'],
        ['code' => 'NZ', 'iso3' => 'NZL', 'name' => 'New Zealand', 'phone_code' => '+64', 'timezone' => 'Pacific/Auckland'],
        ['code' => 'OM', 'iso3' => 'OMN', 'name' => 'Oman', 'phone_code' => '+968', 'timezone' => 'Asia/Muscat'],
        ['code' => 'PA', 'iso3' => 'PAN', 'name' => 'Panama', 'phone_code' => '+507', 'timezone' => 'America/Panama'],
        ['code' => 'PE', 'iso3' => 'PER', 'name' => 'Peru', 'phone_code' => '+51', 'timezone' => 'America/Lima'],
        ['code' => 'PF', 'iso3' => 'PYF', 'name' => 'French Polynesia', 'phone_code' => '+689', 'timezone' => 'Pacific/Tahiti'],
        ['code' => 'PG', 'iso3' => 'PNG', 'name' => 'Papua New Guinea', 'phone_code' => '+675', 'timezone' => 'Pacific/Port_Moresby'],
        ['code' => 'PH', 'iso3' => 'PHL', 'name' => 'Philippines', 'phone_code' => '+63', 'timezone' => 'Asia/Manila'],
        ['code' => 'PK', 'iso3' => 'PAK', 'name' => 'Pakistan', 'phone_code' => '+92', 'timezone' => 'Asia/Karachi'],
        ['code' => 'PL', 'iso3' => 'POL', 'name' => 'Poland', 'phone_code' => '+48', 'timezone' => 'Europe/Warsaw'],
        ['code' => 'PM', 'iso3' => 'SPM', 'name' => 'Saint Pierre and Miquelon', 'phone_code' => '+508', 'timezone' => 'America/Miquelon'],
        ['code' => 'PN', 'iso3' => 'PCN', 'name' => 'Pitcairn Islands', 'phone_code' => '+64', 'timezone' => 'Pacific/Pitcairn'],
        ['code' => 'PR', 'iso3' => 'PRI', 'name' => 'Puerto Rico', 'phone_code' => '+1-787', 'timezone' => 'America/Puerto_Rico'],
        ['code' => 'PS', 'iso3' => 'PSE', 'name' => 'Palestine', 'phone_code' => '+970', 'timezone' => 'Asia/Gaza'],
        ['code' => 'PT', 'iso3' => 'PRT', 'name' => 'Portugal', 'phone_code' => '+351', 'timezone' => 'Europe/Lisbon'],
        ['code' => 'PW', 'iso3' => 'PLW', 'name' => 'Palau', 'phone_code' => '+680', 'timezone' => 'Pacific/Palau'],
        ['code' => 'PY', 'iso3' => 'PRY', 'name' => 'Paraguay', 'phone_code' => '+595', 'timezone' => 'America/Asuncion'],
        ['code' => 'QA', 'iso3' => 'QAT', 'name' => 'Qatar', 'phone_code' => '+974', 'timezone' => 'Asia/Qatar'],
        ['code' => 'RE', 'iso3' => 'REU', 'name' => 'Réunion', 'phone_code' => '+262', 'timezone' => 'Indian/Reunion'],
        ['code' => 'RO', 'iso3' => 'ROU', 'name' => 'Romania', 'phone_code' => '+40', 'timezone' => 'Europe/Bucharest'],
        ['code' => 'RS', 'iso3' => 'SRB', 'name' => 'Serbia', 'phone_code' => '+381', 'timezone' => 'Europe/Belgrade'],
        ['code' => 'RU', 'iso3' => 'RUS', 'name' => 'Russia', 'phone_code' => '+7', 'timezone' => 'Europe/Moscow'],
        ['code' => 'RW', 'iso3' => 'RWA', 'name' => 'Rwanda', 'phone_code' => '+250', 'timezone' => 'Africa/Kigali'],
        ['code' => 'SA', 'iso3' => 'SAU', 'name' => 'Saudi Arabia', 'phone_code' => '+966', 'timezone' => 'Asia/Riyadh'],
        ['code' => 'SB', 'iso3' => 'SLB', 'name' => 'Solomon Islands', 'phone_code' => '+677', 'timezone' => 'Pacific/Guadalcanal'],
        ['code' => 'SC', 'iso3' => 'SYC', 'name' => 'Seychelles', 'phone_code' => '+248', 'timezone' => 'Indian/Mahe'],
        ['code' => 'SD', 'iso3' => 'SDN', 'name' => 'Sudan', 'phone_code' => '+249', 'timezone' => 'Africa/Khartoum'],
        ['code' => 'SE', 'iso3' => 'SWE', 'name' => 'Sweden', 'phone_code' => '+46', 'timezone' => 'Europe/Stockholm'],
        ['code' => 'SG', 'iso3' => 'SGP', 'name' => 'Singapore', 'phone_code' => '+65', 'timezone' => 'Asia/Singapore'],
        ['code' => 'SH', 'iso3' => 'SHN', 'name' => 'Saint Helena', 'phone_code' => '+290', 'timezone' => 'Atlantic/St_Helena'],
        ['code' => 'SI', 'iso3' => 'SVN', 'name' => 'Slovenia', 'phone_code' => '+386', 'timezone' => 'Europe/Ljubljana'],
        ['code' => 'SJ', 'iso3' => 'SJM', 'name' => 'Svalbard and Jan Mayen', 'phone_code' => '+47', 'timezone' => 'Arctic/Longyearbyen'],
        ['code' => 'SK', 'iso3' => 'SVK', 'name' => 'Slovakia', 'phone_code' => '+421', 'timezone' => 'Europe/Bratislava'],
        ['code' => 'SL', 'iso3' => 'SLE', 'name' => 'Sierra Leone', 'phone_code' => '+232', 'timezone' => 'Africa/Freetown'],
        ['code' => 'SM', 'iso3' => 'SMR', 'name' => 'San Marino', 'phone_code' => '+378', 'timezone' => 'Europe/San_Marino'],
        ['code' => 'SN', 'iso3' => 'SEN', 'name' => 'Senegal', 'phone_code' => '+221', 'timezone' => 'Africa/Dakar'],
        ['code' => 'SO', 'iso3' => 'SOM', 'name' => 'Somalia', 'phone_code' => '+252', 'timezone' => 'Africa/Mogadishu'],
        ['code' => 'SR', 'iso3' => 'SUR', 'name' => 'Suriname', 'phone_code' => '+597', 'timezone' => 'America/Paramaribo'],
        ['code' => 'SS', 'iso3' => 'SSD', 'name' => 'South Sudan', 'phone_code' => '+211', 'timezone' => 'Africa/Juba'],
        ['code' => 'ST', 'iso3' => 'STP', 'name' => 'Sao Tome and Principe', 'phone_code' => '+239', 'timezone' => 'Africa/Sao_Tome'],
        ['code' => 'SV', 'iso3' => 'SLV', 'name' => 'El Salvador', 'phone_code' => '+503', 'timezone' => 'America/El_Salvador'],
        ['code' => 'SX', 'iso3' => 'SXM', 'name' => 'Sint Maarten', 'phone_code' => '+1-721', 'timezone' => 'America/Lower_Princes'],
        ['code' => 'SY', 'iso3' => 'SYR', 'name' => 'Syria', 'phone_code' => '+963', 'timezone' => 'Asia/Damascus'],
        ['code' => 'SZ', 'iso3' => 'SWZ', 'name' => 'Eswatini', 'phone_code' => '+268', 'timezone' => 'Africa/Mbabane'],
        ['code' => 'TC', 'iso3' => 'TCA', 'name' => 'Turks and Caicos Islands', 'phone_code' => '+1-649', 'timezone' => 'America/Grand_Turk'],
        ['code' => 'TD', 'iso3' => 'TCD', 'name' => 'Chad', 'phone_code' => '+235', 'timezone' => 'Africa/Ndjamena'],
        ['code' => 'TF', 'iso3' => 'ATF', 'name' => 'French Southern Territories', 'phone_code' => '+262', 'timezone' => 'Indian/Kerguelen'],
        ['code' => 'TG', 'iso3' => 'TGO', 'name' => 'Togo', 'phone_code' => '+228', 'timezone' => 'Africa/Lome'],
        ['code' => 'TH', 'iso3' => 'THA', 'name' => 'Thailand', 'phone_code' => '+66', 'timezone' => 'Asia/Bangkok'],
        ['code' => 'TJ', 'iso3' => 'TJK', 'name' => 'Tajikistan', 'phone_code' => '+992', 'timezone' => 'Asia/Dushanbe'],
        ['code' => 'TK', 'iso3' => 'TKL', 'name' => 'Tokelau', 'phone_code' => '+690', 'timezone' => 'Pacific/Fakaofo'],
        ['code' => 'TL', 'iso3' => 'TLS', 'name' => 'Timor-Leste', 'phone_code' => '+670', 'timezone' => 'Asia/Dili'],
        ['code' => 'TM', 'iso3' => 'TKM', 'name' => 'Turkmenistan', 'phone_code' => '+993', 'timezone' => 'Asia/Ashgabat'],
        ['code' => 'TN', 'iso3' => 'TUN', 'name' => 'Tunisia', 'phone_code' => '+216', 'timezone' => 'Africa/Tunis'],
        ['code' => 'TO', 'iso3' => 'TON', 'name' => 'Tonga', 'phone_code' => '+676', 'timezone' => 'Pacific/Tongatapu'],
        ['code' => 'TR', 'iso3' => 'TUR', 'name' => 'Turkey', 'phone_code' => '+90', 'timezone' => 'Europe/Istanbul'],
        ['code' => 'TT', 'iso3' => 'TTO', 'name' => 'Trinidad and Tobago', 'phone_code' => '+1-868', 'timezone' => 'America/Port_of_Spain'],
        ['code' => 'TV', 'iso3' => 'TUV', 'name' => 'Tuvalu', 'phone_code' => '+688', 'timezone' => 'Pacific/Funafuti'],
        ['code' => 'TW', 'iso3' => 'TWN', 'name' => 'Taiwan', 'phone_code' => '+886', 'timezone' => 'Asia/Taipei'],
        ['code' => 'TZ', 'iso3' => 'TZA', 'name' => 'Tanzania', 'phone_code' => '+255', 'timezone' => 'Africa/Dar_es_Salaam'],
        ['code' => 'UA', 'iso3' => 'UKR', 'name' => 'Ukraine', 'phone_code' => '+380', 'timezone' => 'Europe/Kyiv'],
        ['code' => 'UG', 'iso3' => 'UGA', 'name' => 'Uganda', 'phone_code' => '+256', 'timezone' => 'Africa/Kampala'],
        ['code' => 'UM', 'iso3' => 'UMI', 'name' => 'United States Minor Outlying Islands', 'phone_code' => '+1', 'timezone' => 'Pacific/Wake'],
        ['code' => 'US', 'iso3' => 'USA', 'name' => 'United States', 'phone_code' => '+1', 'timezone' => 'America/New_York'],
        ['code' => 'UY', 'iso3' => 'URY', 'name' => 'Uruguay', 'phone_code' => '+598', 'timezone' => 'America/Montevideo'],
        ['code' => 'UZ', 'iso3' => 'UZB', 'name' => 'Uzbekistan', 'phone_code' => '+998', 'timezone' => 'Asia/Tashkent'],
        ['code' => 'VA', 'iso3' => 'VAT', 'name' => 'Vatican City', 'phone_code' => '+379', 'timezone' => 'Europe/Vatican'],
        ['code' => 'VC', 'iso3' => 'VCT', 'name' => 'Saint Vincent and the Grenadines', 'phone_code' => '+1-784', 'timezone' => 'America/St_Vincent'],
        ['code' => 'VE', 'iso3' => 'VEN', 'name' => 'Venezuela', 'phone_code' => '+58', 'timezone' => 'America/Caracas'],
        ['code' => 'VG', 'iso3' => 'VGB', 'name' => 'Virgin Islands (British)', 'phone_code' => '+1-284', 'timezone' => 'America/Tortola'],
        ['code' => 'VI', 'iso3' => 'VIR', 'name' => 'Virgin Islands (U.S.)', 'phone_code' => '+1-340', 'timezone' => 'America/St_Thomas'],
        ['code' => 'VN', 'iso3' => 'VNM', 'name' => 'Vietnam', 'phone_code' => '+84', 'timezone' => 'Asia/Ho_Chi_Minh'],
        ['code' => 'VU', 'iso3' => 'VUT', 'name' => 'Vanuatu', 'phone_code' => '+678', 'timezone' => 'Pacific/Efate'],
        ['code' => 'WF', 'iso3' => 'WLF', 'name' => 'Wallis and Futuna', 'phone_code' => '+681', 'timezone' => 'Pacific/Wallis'],
        ['code' => 'WS', 'iso3' => 'WSM', 'name' => 'Samoa', 'phone_code' => '+685', 'timezone' => 'Pacific/Apia'],
        ['code' => 'YE', 'iso3' => 'YEM', 'name' => 'Yemen', 'phone_code' => '+967', 'timezone' => 'Asia/Aden'],
        ['code' => 'YT', 'iso3' => 'MYT', 'name' => 'Mayotte', 'phone_code' => '+262', 'timezone' => 'Indian/Mayotte'],
        ['code' => 'ZA', 'iso3' => 'ZAF', 'name' => 'South Africa', 'phone_code' => '+27', 'timezone' => 'Africa/Johannesburg'],
        ['code' => 'ZM', 'iso3' => 'ZMB', 'name' => 'Zambia', 'phone_code' => '+260', 'timezone' => 'Africa/Lusaka'],
        ['code' => 'ZW', 'iso3' => 'ZWE', 'name' => 'Zimbabwe', 'phone_code' => '+263', 'timezone' => 'Africa/Harare'],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::COUNTRIES as $c) {
            // 1. Seed ref_countries
            DB::table('ref_countries')->updateOrInsert(
                ['code' => $c['code']],
                [
                    'code' => $c['code'],
                    'iso3' => $c['iso3'],
                    'name' => $c['name'],
                    'phone_code' => $c['phone_code'],
                    'timezone' => $c['timezone'],
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            // 2. Sync global country_regions table for party / address reference
            DB::table('country_regions')->updateOrInsert(
                ['code' => $c['code']],
                [
                    'code' => $c['code'],
                    'iso3' => $c['iso3'],
                    'name' => $c['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            // 3. Seed administrative division timezone mapping
            DB::table('ref_administrative_division_timezones')->updateOrInsert(
                ['division_type' => 'country', 'division_id' => $c['code']],
                [
                    'id' => (string) Str::ulid(),
                    'timezone' => $c['timezone'],
                    'is_default' => true,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            // 4. Seed default address parameters if not exists
            DB::table('ref_address_parameters')->updateOrInsert(
                ['country_code' => $c['code']],
                [
                    'country_code' => $c['code'],
                    'use_province' => true,
                    'use_regency' => true,
                    'use_district' => true,
                    'use_village' => true,
                    'use_rt_rw' => in_array($c['code'], ['ID', 'VN', 'TW', 'JP']),
                    'use_postal_code' => true,
                    'use_building' => true,
                    'address_format' => match ($c['code']) {
                        'ID' => '{street}, RT {rt}/RW {rw}, Kel. {village}, Kec. {district}, {regency}, {province} {postal_code}, {country}',
                        'MY' => '{street}, {village}, {district}, {postal_code} {regency}, {province}, {country}',
                        'US' => '{street}, {village}, {province} {postal_code}, {country}',
                        'GB' => '{street}, {village}, {district}, {province}, {postal_code}, {country}',
                        'JP' => '〒{postal_code} {province} {regency} {district} {village} {street}, {country}',
                        default => '{street}, {village}, {district}, {regency}, {province} {postal_code}, {country}',
                    },
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // 5. Seed standard hierarchy levels for major international jurisdictions
        $this->seedMajorHierarchyLevels($now);
    }

    private function seedMajorHierarchyLevels($now): void
    {
        $levels = [
            // Indonesia
            ['country_code' => 'ID', 'level' => 1, 'level_code' => 'province', 'level_name' => 'Provinsi', 'description' => 'Tingkat 1: Provinsi'],
            ['country_code' => 'ID', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'Kabupaten/Kota', 'description' => 'Tingkat 2: Kabupaten & Kota'],
            ['country_code' => 'ID', 'level' => 3, 'level_code' => 'district', 'level_name' => 'Kecamatan', 'description' => 'Tingkat 3: Kecamatan / Distrik'],
            ['country_code' => 'ID', 'level' => 4, 'level_code' => 'village',  'level_name' => 'Desa/Kelurahan', 'description' => 'Tingkat 4: Desa, Kelurahan, Kampung, Nagari'],
            ['country_code' => 'ID', 'level' => 5, 'level_code' => 'street',   'level_name' => 'RT / RW', 'description' => 'Tingkat 5: Rukun Tetangga / Rukun Warga'],

            // Malaysia
            ['country_code' => 'MY', 'level' => 1, 'level_code' => 'province', 'level_name' => 'Negeri / State', 'description' => 'Tingkat 1: Negeri / State'],
            ['country_code' => 'MY', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'Daerah / District', 'description' => 'Tingkat 2: Daerah / District'],
            ['country_code' => 'MY', 'level' => 3, 'level_code' => 'district', 'level_name' => 'Mukim / Sub-district', 'description' => 'Tingkat 3: Mukim / Sub-district'],
            ['country_code' => 'MY', 'level' => 4, 'level_code' => 'village',  'level_name' => 'Bandar / Kampung', 'description' => 'Tingkat 4: Bandar / Kampung'],

            // Singapore
            ['country_code' => 'SG', 'level' => 1, 'level_code' => 'province', 'level_name' => 'Region', 'description' => 'Tingkat 1: Region / CDC District'],
            ['country_code' => 'SG', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'Planning Area', 'description' => 'Tingkat 2: Planning Area'],
            ['country_code' => 'SG', 'level' => 3, 'level_code' => 'district', 'level_name' => 'Subzone', 'description' => 'Tingkat 3: Subzone'],
            ['country_code' => 'SG', 'level' => 4, 'level_code' => 'village',  'level_name' => 'Estate / Postal Area', 'description' => 'Tingkat 4: Estate / Postal Area'],

            // United States
            ['country_code' => 'US', 'level' => 1, 'level_code' => 'province', 'level_name' => 'State', 'description' => 'Level 1: State / Territory'],
            ['country_code' => 'US', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'County', 'description' => 'Level 2: County / Parish / Borough'],
            ['country_code' => 'US', 'level' => 3, 'level_code' => 'district', 'level_name' => 'City / Township', 'description' => 'Level 3: City / Municipality / Township'],
            ['country_code' => 'US', 'level' => 4, 'level_code' => 'village',  'level_name' => 'Neighborhood / ZIP Area', 'description' => 'Level 4: Neighborhood / ZIP Area'],

            // United Kingdom
            ['country_code' => 'GB', 'level' => 1, 'level_code' => 'province', 'level_name' => 'Country / Region', 'description' => 'Level 1: England, Scotland, Wales, Northern Ireland'],
            ['country_code' => 'GB', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'County / Unitary Authority', 'description' => 'Level 2: County / Unitary Authority'],
            ['country_code' => 'GB', 'level' => 3, 'level_code' => 'district', 'level_name' => 'District / Borough', 'description' => 'Level 3: District / Borough'],
            ['country_code' => 'GB', 'level' => 4, 'level_code' => 'village',  'level_name' => 'Postal Town / Parish', 'description' => 'Level 4: Postal Town / Civil Parish'],

            // Australia
            ['country_code' => 'AU', 'level' => 1, 'level_code' => 'province', 'level_name' => 'State / Territory', 'description' => 'Level 1: State / Territory'],
            ['country_code' => 'AU', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'Local Government Area', 'description' => 'Level 2: LGA (City, Shire, Council)'],
            ['country_code' => 'AU', 'level' => 3, 'level_code' => 'district', 'level_name' => 'District', 'description' => 'Level 3: District'],
            ['country_code' => 'AU', 'level' => 4, 'level_code' => 'village',  'level_name' => 'Suburb / Locality', 'description' => 'Level 4: Suburb / Locality'],

            // Japan
            ['country_code' => 'JP', 'level' => 1, 'level_code' => 'province', 'level_name' => 'Prefecture (都道府県)', 'description' => 'Level 1: To / Do / Fu / Ken (47 Prefectures)'],
            ['country_code' => 'JP', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'City / District (市区町村)', 'description' => 'Level 2: Shi / Ku / Machi / Mura'],
            ['country_code' => 'JP', 'level' => 3, 'level_code' => 'district', 'level_name' => 'Ward / Town (町・大字)', 'description' => 'Level 3: Machi / Oaza'],
            ['country_code' => 'JP', 'level' => 4, 'level_code' => 'village',  'level_name' => 'Chome / Block (丁目・番地)', 'description' => 'Level 4: Chome / Block'],

            // Germany
            ['country_code' => 'DE', 'level' => 1, 'level_code' => 'province', 'level_name' => 'Bundesland', 'description' => 'Level 1: Federal State (16 Bundesländer)'],
            ['country_code' => 'DE', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'Landkreis / Kreisfreie Stadt', 'description' => 'Level 2: District / City District'],
            ['country_code' => 'DE', 'level' => 3, 'level_code' => 'district', 'level_name' => 'Gemeinde / Samtgemeinde', 'description' => 'Level 3: Municipality'],
            ['country_code' => 'DE', 'level' => 4, 'level_code' => 'village',  'level_name' => 'Ortsteil', 'description' => 'Level 4: Local Sub-district'],

            // Thailand
            ['country_code' => 'TH', 'level' => 1, 'level_code' => 'province', 'level_name' => 'Changwat (จังหวัด)', 'description' => 'Level 1: Province (76 Changwat + Bangkok)'],
            ['country_code' => 'TH', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'Amphoe (อำเภอ)', 'description' => 'Level 2: District / Khet in Bangkok'],
            ['country_code' => 'TH', 'level' => 3, 'level_code' => 'district', 'level_name' => 'Tambon (ตำบล)', 'description' => 'Level 3: Sub-district / Khwaeng in Bangkok'],
            ['country_code' => 'TH', 'level' => 4, 'level_code' => 'village',  'level_name' => 'Muban (หมู่บ้าน)', 'description' => 'Level 4: Village / Community'],

            // Philippines
            ['country_code' => 'PH', 'level' => 1, 'level_code' => 'province', 'level_name' => 'Province / Region', 'description' => 'Level 1: Province / Autonomous Region'],
            ['country_code' => 'PH', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'City / Municipality', 'description' => 'Level 2: City / Municipality'],
            ['country_code' => 'PH', 'level' => 3, 'level_code' => 'district', 'level_name' => 'District / Zone', 'description' => 'Level 3: District / Zone'],
            ['country_code' => 'PH', 'level' => 4, 'level_code' => 'village',  'level_name' => 'Barangay', 'description' => 'Level 4: Barangay (Smallest local government unit)'],

            // Vietnam
            ['country_code' => 'VN', 'level' => 1, 'level_code' => 'province', 'level_name' => 'Tỉnh / Thành phố', 'description' => 'Level 1: Province / Centrally Run City'],
            ['country_code' => 'VN', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'Quận / Huyện / Thị xã', 'description' => 'Level 2: District / Town'],
            ['country_code' => 'VN', 'level' => 3, 'level_code' => 'district', 'level_name' => 'Phường / Xã / Thị trấn', 'description' => 'Level 3: Ward / Commune / Township'],
            ['country_code' => 'VN', 'level' => 4, 'level_code' => 'village',  'level_name' => 'Thôn / Ấp / Tổ dân phố', 'description' => 'Level 4: Village / Hamlet / Neighborhood'],
        ];

        foreach ($levels as $lvl) {
            DB::table('ref_country_hierarchy_levels')->updateOrInsert(
                ['country_code' => $lvl['country_code'], 'level' => $lvl['level']],
                [
                    'id' => (string) Str::ulid(),
                    'country_code' => $lvl['country_code'],
                    'level' => $lvl['level'],
                    'level_code' => $lvl['level_code'],
                    'level_name' => $lvl['level_name'],
                    'description' => $lvl['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
