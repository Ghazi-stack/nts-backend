const fs = require('fs');
const path = require('path');
const countries = require('world-countries');
const tzLookup = require('tz-lookup');

const ROOT = process.cwd();

const OUTPUT = path.join(ROOT, 'config', 'country_defaults.php');
const ISSUES = path.join(ROOT, 'country-defaults-issues.json');

const GEONAMES_CITIES = path.join(ROOT, 'scripts', 'cities15000.txt');

const UNIT_MAP = {
    kilogram: 'kg',
    gram: 'g',
    pound: 'lbs',
    ounce: 'oz',
    tonne: 'tonnes',
    'metric-ton': 'tonnes',
    'metric-tonne': 'tonnes',
    'short-ton': 'tonnes',
    inch: 'in',
    centimeter: 'cm',
};

const WEIGHT_UNITS = ['lbs', 'oz', 'kg', 'g', 'tonnes'];
const LENGTH_UNITS = ['cm', 'in'];

const issues = [];
const output = [];

function readJson(file) {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function localeExists(packageName, locale, filename) {
    return fs.existsSync(
        path.join(
            ROOT,
            'node_modules',
            packageName,
            'main',
            locale,
            filename
        )
    );
}

// Prefer country-specific English, then generic English.
function getLocale(countryCode) {
    const candidates = [
        `en-${countryCode}`,
        'en',
    ];

    return candidates.find(locale =>
        localeExists('cldr-numbers-full', locale, 'numbers.json') &&
        localeExists('cldr-dates-full', locale, 'ca-gregorian.json')
    ) || null;
}

function readLocaleData(locale) {
    const numbersFile = path.join(
        ROOT,
        'node_modules/cldr-numbers-full/main',
        locale,
        'numbers.json'
    );

    const datesFile = path.join(
        ROOT,
        'node_modules/cldr-dates-full/main',
        locale,
        'ca-gregorian.json'
    );

    const numbers = readJson(numbersFile).main[locale].numbers;

    const dates = readJson(datesFile)
        .main[locale]
        .dates
        .calendars
        .gregorian;

    const numberingSystem = numbers.defaultNumberingSystem;

    return {
        symbols: numbers[`symbols-numberSystem-${numberingSystem}`],
        dates,
    };
}

// Load CLDR unit preferences once.
const unitPreferenceFile = path.join(
    ROOT,
    'node_modules/cldr-core/supplemental/unitPreferenceData.json'
);

const unitPreferenceData =
    readJson(unitPreferenceFile).supplemental.unitPreferenceData;

// Support CLDR array and object representations.
function normalizePreferences(value) {
    if (!value) return [];

    if (Array.isArray(value)) {
        return value;
    }

    if (typeof value !== 'object') {
        return [];
    }

    return Object.entries(value).map(([key, item]) => {
        if (item && typeof item === 'object') {
            return {
                region: item.region || item._territories || key,
                unit: item.unit || item._unit,
                ...item,
            };
        }

        return {
            region: key,
            unit: item,
        };
    });
}


function getUnitPreference(countryCode, category) {
    const preferences = unitPreferenceData[category]?.default;

    if (!preferences) return [];

    // Country-specific preferences take priority.
    // Otherwise use CLDR's global territory.
    return preferences[countryCode] || preferences['001'] || [];
}


function mapUnit(unit, allowed) {
    if (!unit) return null;

    if (Array.isArray(unit)) {
        for (const candidate of unit) {
            const result = mapUnit(candidate, allowed);
            if (result) return result;
        }

        return null;
    }

    const baseUnit = String(unit).split('-')[0];
    const mapped = UNIT_MAP[unit] || UNIT_MAP[baseUnit];

    return mapped && allowed.includes(mapped) ? mapped : null;
}

// Read GeoNames national capital records.
function loadCapitalRecords() {
    if (!fs.existsSync(GEONAMES_CITIES)) {
        throw new Error(
            'Missing scripts/cities15000.txt. Download and extract GeoNames cities15000.zip first.'
        );
    }

    const lines = fs.readFileSync(GEONAMES_CITIES, 'utf8').split(/\r?\n/);

    const records = new Map();

    for (const line of lines) {
        if (!line) continue;

        const fields = line.split('\t');

        const name = fields[1];
        const latitude = Number(fields[4]);
        const longitude = Number(fields[5]);
        const countryCode = fields[8];
        const featureClass = fields[6];
        const featureCode = fields[7];

        // PPLC = seat of government / national capital.
        if (
            featureClass !== 'P' ||
            featureCode !== 'PPLC' ||
            !countryCode ||
            !Number.isFinite(latitude) ||
            !Number.isFinite(longitude)
        ) {
            continue;
        }

        if (!records.has(countryCode)) {
            records.set(countryCode, []);
        }

        records.get(countryCode).push({
            name,
            latitude,
            longitude,
        });
    }

    return records;
}

function getCapital(countryCode, records) {
    const matches = records.get(countryCode) || [];

    if (matches.length === 1) {
        return matches[0];
    }

    if (matches.length > 1) {
        return {
            error: `Multiple PPLC records found: ${matches.map(x => x.name).join(', ')}`,
        };
    }

    return null;
}

function getTimezone(latitude, longitude) {
    return tzLookup(latitude, longitude);
}

function getTimezoneOffsets(timezone) {
    const year = new Date().getUTCFullYear();
    const offsets = new Set();

    for (let month = 0; month < 12; month++) {
        const date = new Date(Date.UTC(year, month, 15, 12));

        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: timezone,
            timeZoneName: 'longOffset',
        }).formatToParts(date);

        const offset = parts.find(
            part => part.type === 'timeZoneName'
        )?.value;

        if (offset) offsets.add(offset);
    }

    return [...offsets];
}

function hasDst(timezone) {
    return getTimezoneOffsets(timezone).length > 1;
}

// Convert CLDR date/time patterns to PHP date() format.

function convertPattern(pattern) {
    const tokens = {
        yyyy: 'Y',
        yy: 'y',
        y: 'Y',
        MMMM: 'F',
        MMM: 'M',
        MM: 'm',
        M: 'n',
        dd: 'd',
        d: 'j',
        EEEE: 'l',
        EEE: 'D',
        EE: 'D',
        HH: 'H',
        H: 'G',
        hh: 'h',
        h: 'g',
        mm: 'i',
        m: 'i',
        ss: 's',
        a: 'A',
    };

    let result = '';
    const unsupported = [];

    for (let i = 0; i < pattern.length;) {
        const char = pattern[i];

        if (char === "'") {
            i++;

            let literal = '';

            while (i < pattern.length) {
                if (pattern[i] === "'") {
                    if (pattern[i + 1] === "'") {
                        literal += "'";
                        i += 2;
                        continue;
                    }

                    i++;
                    break;
                }

                literal += pattern[i++];
            }

            result += literal.split('').map(c => '\\' + c).join('');
            continue;
        }

        if (/[a-zA-Z]/.test(char)) {
            let end = i + 1;

            while (end < pattern.length && pattern[end] === char) {
                end++;
            }

            const token = pattern.slice(i, end);

            if (Object.prototype.hasOwnProperty.call(tokens, token)) {
                result += tokens[token];
            } else {
                unsupported.push(token);
                result += token.split('').map(c => '\\' + c).join('');
            }

            i = end;
            continue;
        }

        if (char === '\\') {
            result += '\\\\';
        } else {
            result += char;
        }

        i++;
    }

    return {
        format: result,
        unsupported,
    };
}

function php(value) {
    return "'" + String(value)
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'") + "'";
}

// Select a supported unit using explicit preference rules.
// Do not silently invent a CLDR value.

function chooseWeightUnit(countryCode) {
    const preferences = getUnitPreference(countryCode, 'mass');

    const units = preferences.map(item => item.unit);

    if (['US', 'GB'].includes(countryCode)) {
        return {
            value: 'lbs',
            source: 'CLDR-derived',
        };
    }

    if (units.includes('kilogram')) {
        return {
            value: 'kg',
            source: 'CLDR-derived',
        };
    }

    if (units.includes('gram')) {
        return {
            value: 'g',
            source: 'CLDR-derived',
        };
    }

    return {
        value: 'kg',
        source: 'application-fallback',
    };
}

function chooseLengthUnit(countryCode) {
    const preferences = getUnitPreference(countryCode, 'length');

    const units = preferences.map(item => item.unit);

    if (['US', 'GB'].includes(countryCode)) {
        return {
            value: 'in',
            source: 'CLDR-derived',
        };
    }

    if (units.includes('centimeter')) {
        return {
            value: 'cm',
            source: 'CLDR-derived',
        };
    }

    return {
        value: 'cm',
        source: 'application-fallback',
    };
}

function generate() {
    const capitalRecords = loadCapitalRecords();

    for (const country of countries) {
        if (!country.cca2 || !country.name?.common) continue;

        const code = country.cca2;
        const name = country.name.common;

        const locale = getLocale(code);

        if (!locale) {
            issues.push({
                country: code,
                field: 'locale',
                message: 'No usable CLDR locale found.',
            });
            continue;
        }

        let localeData;

        try {
            localeData = readLocaleData(locale);
        } catch (error) {
            issues.push({
                country: code,
                field: 'locale',
                message: error.message,
            });
            continue;
        }

        const capital = getCapital(code, capitalRecords);

        if (!capital || capital.error) {
            issues.push({
                country: code,
                field: 'timezone',
                message: capital?.error || 'National capital not found in GeoNames PPLC records.',
            });
            continue;
        }

        
        let timezone;

        if (!capital || !capital.latitude || !capital.longitude) {
            issues.push({
                country: code,
                field: 'timezone',
                message: 'No defaults found for this country.',
            });

            continue;
        }

        try {
            timezone = getTimezone(
                capital.latitude,
                capital.longitude
            );
        } catch (error) {
            issues.push({
                country: code,
                field: 'timezone',
                message: error.message,
            });

            continue;
        }


        const weight = chooseWeightUnit(code);
        const length = chooseLengthUnit(code);

        if (!weight.value) {
            issues.push({
                country: code,
                field: 'weight_unit',
                message: 'No supported CLDR default unit could be mapped.',
            });
        }

        if (!length.value) {
            issues.push({
                country: code,
                field: 'length_unit',
                message: 'No supported CLDR default unit could be mapped.',
            });
        }

        const datePattern = localeData.dates.dateFormats.short;
        const timePattern = localeData.dates.timeFormats.short;

        const dateFormat = convertPattern(datePattern);
        const extendedFormat = convertPattern(
            `${datePattern} ' @ ' ${timePattern}`
        );

        const unsupported = [
            ...dateFormat.unsupported,
            ...extendedFormat.unsupported,
        ];

        if (unsupported.length) {
            issues.push({
                country: code,
                field: 'date_format',
                message: `Unsupported CLDR pattern tokens: ${[...new Set(unsupported)].join(', ')}`,
            });
        }

        output.push({
            country: {
                default_country: name,
                country_code: code,
            },

            physical_dimensions: {
                weight_unit: weight.value || 'kg',
                length_unit: length.value || 'cm',
                thousands_token: localeData.symbols.group,
                decimal_token: localeData.symbols.decimal,
                decimal_places: 2,
                factoring_dimension: 'product_depth',
            },

            date_timezone: {
                timezone,
                dst_enabled: hasDst(timezone),
                date_format: dateFormat.format,
                extended_date_time_format: extendedFormat.format,
            },
        });
    }
}

function generatePhp() {
    const phpEntries = output.map(item => {
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
    });

    const content = `<?php

return [
${phpEntries.join(',\n')}
];
`;

    fs.mkdirSync(path.dirname(OUTPUT), { recursive: true });
    fs.writeFileSync(OUTPUT, content, 'utf8');
}

function main() {
    generate();
    generatePhp();

    fs.writeFileSync(
        ISSUES,
        JSON.stringify(issues, null, 2),
        'utf8'
    );

    console.log('\nCountry generation completed');
    console.log('-----------------------------');
    console.log('Generated countries:', output.length);
    console.log('Issues:', issues.length);
    console.log('PHP file:', OUTPUT);
    console.log('Issue report:', ISSUES);

    const missingTimezones = issues.filter(
        issue => issue.field === 'timezone'
    ).length;

    console.log('Missing timezone mappings:', missingTimezones);
}

try {
    main();
} catch (error) {
    console.error('Generation failed:', error);
    process.exitCode = 1;
}