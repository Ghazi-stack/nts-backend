const fs = require('fs');
const path = require('path');
const countries = require('world-countries');
const tzLookup = require('tz-lookup');

const ROOT = process.cwd();
const OUTPUT = path.join(ROOT, 'config', 'country_defaults.php');

const UNIT_MAP = {
    kilogram: 'kg',
    gram: 'g',
    pound: 'lbs',
    ounce: 'oz',
    tonne: 'tonnes',
    inch: 'in',
    centimeter: 'cm',
};

const WEIGHT_UNITS = ['kg', 'g', 'lbs', 'oz', 'tonnes'];
const LENGTH_UNITS = ['cm', 'in'];

function readJson(file) {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function localeExists(packageName, locale, filename) {
    return fs.existsSync(
        path.join(ROOT, 'node_modules', packageName, 'main', locale, filename)
    );
}

function getLocale(countryCode, language) {
    const candidates = [
        `${language}-${countryCode}`,
        language,
        'en',
    ];

    for (const locale of candidates) {
        if (
            localeExists('cldr-numbers-full', locale, 'numbers.json') &&
            localeExists('cldr-dates-full', locale, 'ca-gregorian.json')
        ) {
            return locale;
        }
    }

    return null;
}

function readLocaleData(locale) {
    const numbers = readJson(
        path.join(ROOT, 'node_modules/cldr-numbers-full/main', locale, 'numbers.json')
    ).main[locale].numbers;

    const dates = readJson(
        path.join(ROOT, 'node_modules/cldr-dates-full/main', locale, 'ca-gregorian.json')
    ).main[locale].dates.calendars.gregorian;

    const numberingSystem = numbers.defaultNumberingSystem;

    return {
        numbers,
        symbols: numbers[`symbols-numberSystem-${numberingSystem}`],
        numberPattern: numbers[`decimalFormats-numberSystem-${numberingSystem}`]?.standard,
        dates,
    };
}


function getUnitPreference(countryCode, category) {
    const file = path.join(
        ROOT,
        'node_modules/cldr-core/supplemental/unitPreferenceData.json'
    );

    const data = readJson(file).supplemental.unitPreferenceData;
    const entries = data[category]?.default;

    if (!entries) return null;

    // CLDR may store preferences as an array or an object.
    const normalized = Array.isArray(entries)
        ? entries
        : Object.entries(entries).map(([region, value]) => ({
            region,
            ...(typeof value === 'object' ? value : { unit: value }),
        }));

    const preference =
        normalized.find(entry => entry.region === countryCode) ||
        normalized.find(entry => entry.region === '001');

    if (!preference) return null;

    // Some CLDR preference entries contain multiple units.
    return Array.isArray(preference.unit)
        ? preference.unit[0]
        : preference.unit || null;
}


function mapUnit(unit, allowed) {
    if (!unit) return null;

    const baseUnit = unit.split('-')[0];
    const mapped = UNIT_MAP[baseUnit];

    return mapped && allowed.includes(mapped) ? mapped : null;
}

// Convert a subset of CLDR patterns into PHP date() tokens.
// Unsupported patterns are deliberately reported rather than guessed.
function convertPattern(pattern) {
    const tokens = {
        yyyy: 'Y', y: 'Y',
        MMMM: 'F', MMM: 'M', MM: 'm', M: 'n',
        dd: 'd', d: 'j',
        EEEE: 'l', EEE: 'D',
        HH: 'H', H: 'G',
        hh: 'h', h: 'g',
        mm: 'i', ss: 's',
        a: 'A',
    };

    let result = '';
    let unsupported = [];
    let i = 0;

    while (i < pattern.length) {
        if (pattern[i] === "'") {
            i++;
            let literal = '';

            while (i < pattern.length && pattern[i] !== "'") {
                literal += pattern[i++];
            }

            i++;
            result += '\\' + literal.split('').join('\\');
            continue;
        }

        if (/\s/.test(pattern[i])) {
            result += pattern[i++];
            continue;
        }

        if (/[a-zA-Z]/.test(pattern[i])) {
            let j = i + 1;
            while (j < pattern.length && pattern[j] === pattern[i]) j++;

            const part = pattern.slice(i, j);
            if (tokens[part]) {
                result += tokens[part];
            } else {
                unsupported.push(part);
                result += part;
            }

            i = j;
            continue;
        }

        result += pattern[i++];
    }

    return { format: result, unsupported };
}

function php(value) {
    return "'" + String(value)
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'") + "'";
}

function getCountryLocale(country) {
    // Start with English as a practical default.
    // Country-specific language resolution can be refined later.
    return getLocale(country.cca2, 'en') || getLocale(country.cca2, 'ur');
}

const output = [];
const issues = [];

for (const country of countries) {
    if (!country.cca2 || !country.name?.common) continue;
    if (country.independent === false) continue;

    const code = country.cca2;
    const name = country.name.common;

    const locale = getCountryLocale(country);

    if (!locale) {
        issues.push(`${code}: No usable CLDR locale`);
        continue;
    }

    let data;

    try {
        data = readLocaleData(locale);
    } catch (error) {
        issues.push(`${code}: Locale read failed - ${error.message}`);
        continue;
    }

    const weight = mapUnit(
        getUnitPreference(code, 'mass'),
        WEIGHT_UNITS
    );

    const length = mapUnit(
        getUnitPreference(code, 'length'),
        LENGTH_UNITS
    );

    const symbols = data.symbols;

    const capital = country.capitalInfo?.latlng;

    if (!capital || capital.length !== 2) {
        issues.push(`${code}: Missing capital coordinates`);
        continue;
    }

    let timezone;

    try {
        timezone = tzLookup(capital[0], capital[1]);
    } catch {
        issues.push(`${code}: Capital timezone could not be resolved`);
        continue;
    }

    // Determine whether the selected timezone observes DST.
    // Compare January and July offsets in the current year.
    const year = new Date().getFullYear();

    function offset(month) {
        const date = new Date(Date.UTC(year, month, 15, 12));
        const formatted = new Intl.DateTimeFormat('en-US', {
            timeZone: timezone,
            timeZoneName: 'longOffset',
        }).formatToParts(date);

        return formatted.find(part => part.type === 'timeZoneName')?.value;
    }

    const dstEnabled = offset(0) !== offset(6);

    const datePattern = data.dates.dateFormats.short;
    const timePattern = data.dates.timeFormats.short;

    const dateFormat = convertPattern(datePattern);
    const timeFormat = convertPattern(timePattern);

    if (dateFormat.unsupported.length || timeFormat.unsupported.length) {
        issues.push(`${code}: Unsupported date/time pattern tokens`);
    }

    const extendedFormat = convertPattern(
        `${datePattern} ' @ ' ${timePattern}`
    );

    output.push({
        country: {
            default_country: name,
            country_code: code,
        },
        physical_dimensions: {
            weight_unit: weight || 'kg',
            length_unit: length || 'cm',
            thousands_token: symbols.group,
            decimal_token: symbols.decimal,
            decimal_places: 2,
            factoring_dimension: 'product_depth',
        },
        date_timezone: {
            timezone,
            dst_enabled: dstEnabled,
            date_format: dateFormat.format,
            extended_date_time_format: extendedFormat.format,
        },
    });
}

const phpArray = output.map(item => {
    const c = item.country;
    const p = item.physical_dimensions;
    const d = item.date_timezone;

    return `    ${php(c.country_code)} => [
        'country' => [
            'default_country' => ${php(c.default_country)},
            'country_code' => ${php(c.country_code)},
        ],
        'physical_dimensions' => [
            'weight_unit' => ${php(p.weight_unit)},
            'length_unit' => ${php(p.length_unit)},
            'thousands_token' => ${php(p.thousands_token)},
            'decimal_token' => ${php(p.decimal_token)},
            'decimal_places' => 2,
            'factoring_dimension' => 'product_depth',
        ],
        'date_timezone' => [
            'timezone' => ${php(d.timezone)},
            'dst_enabled' => ${d.dst_enabled ? 'true' : 'false'},
            'date_format' => ${php(d.date_format)},
            'extended_date_time_format' => ${php(d.extended_date_time_format)},
        ],
    ]`;
}).join(',\n');

const phpContent = `<?php\n\nreturn [\n${phpArray}\n];\n`;

fs.mkdirSync(path.dirname(OUTPUT), { recursive: true });
fs.writeFileSync(OUTPUT, phpContent);

fs.writeFileSync(
    path.join(ROOT, 'country-defaults-issues.json'),
    JSON.stringify(issues, null, 2)
);

console.log('Country configurations generated:', output.length);
console.log('Issues:', issues.length);
console.log('PHP output:', OUTPUT);
console.log('Issue report: country-defaults-issues.json');
