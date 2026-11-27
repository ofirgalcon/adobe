// Friendly names when listing/tab still have SAP-code placeholders (pre-recheck-in rows).
var adobeSapAppNames = {
    ACR: 'Camera Raw plugin',
    AEFT: 'After Effects',
    AICY: 'InCopy',
    AME: 'Media Encoder',
    AUDT: 'Audition',
    CCXP: 'Creative Cloud Experience',
    CHAR: 'Character Animator',
    COSY: 'Core Sync',
    DRWV: 'Dreamweaver',
    ECR: 'Camera Raw Elements',
    EORG: 'Elements Organizer',
    ESHR: 'Dimension',
    FLPR: 'Animate',
    FRSC: 'Fresco',
    HPRE: 'Premiere Elements Welcome Screen',
    HPSE: 'Photoshop Elements Welcome Screen',
    IDSN: 'InDesign',
    ILST: 'Illustrator',
    KBRG: 'Bridge',
    KCCC: 'Creative Cloud Desktop',
    LRCC: 'Lightroom',
    LTRM: 'Lightroom Classic',
    PHSP: 'Photoshop',
    PPRO: 'Premiere Pro',
    PRE: 'Premiere Elements',
    PRLD: 'Prelude',
    PSE: 'Photoshop Elements',
    RUSH: 'Premiere Rush',
    SBSTA: 'Substance 3D Sampler',
    SBSTD: 'Substance 3D Designer',
    SBSTP: 'Substance 3D Painter',
    SHPR: 'Substance 3D Modeler',
    SPRK: 'XD',
    STGR: 'Substance 3D Stager'
};

var resolveAdobeAppName = function(appName, sapcode) {
    var name = (appName || '').trim();
    var code = (sapcode || '').trim();
    var mapped = adobeSapAppNames[code] || adobeSapAppNames[name] || '';
    if (mapped && (!name || /^[A-Z][A-Z0-9]{1,7}$/.test(name))) {
        return mapped;
    }
    if (code === 'PPRO' && (name === 'Premiere' || name === 'Adobe Premiere')) {
        return 'Premiere Pro';
    }
    return name || appName || '';
};

// Local app icons (custom.js). No-op if helper missing or APP_ICONS_PATH unset/missing.
var formatAdobeAppName = function(colNumber, row) {
    var col = $('td:eq(' + colNumber + ')', row),
        rawName = col.text(),
        sapcode = $('td:eq(' + (colNumber + 1) + ')', row).text().trim(),
        appName = resolveAdobeAppName(rawName, sapcode);

    if (typeof mr.appIcon === 'function' && mr.appIconsEnabled()) {
        col.empty().append(mr.appIcon(appName, appName));
    } else if (appName !== rawName) {
        col.text(appName);
    }
};

// Adobe Up-to-Date Status formatter
var formatAdobeUpToDate = function(col, row) {
    var cell = $('td:eq('+col+')', row),
        value = cell.text().trim();
    
    var labelHtml = '';
    switch (value) {
        case '1':
            labelHtml = mr.label(i18n.t('yes'), 'success');
            break;
        case '0':
            labelHtml = mr.label(i18n.t('no'), 'danger');
            break;
        default:
            labelHtml = mr.label(i18n.t('adobe.unknown_status'), 'warning');
    }
    
    // Use .html() only with trusted content from mr.label() function
    // mr.label() is a trusted MunkiReport function that returns safe HTML
    cell.html(labelHtml);
}

// Adobe SAP Code filter - restricts search to SAP code column only
var sapcode_filter = function(colNumber, d) {
    // Only handle searches that look like SAP codes (uppercase 3-8 characters)
    // This prevents interference with other search functionality
    if (d.search.value && d.search.value.trim() !== '') {
        var searchValue = d.search.value.trim();
        
        // Check if the search looks like a SAP code (uppercase 3-8 characters)
        // SAP codes are typically like "ABC" or "XYZ"
        if (searchValue.match(/^[A-Z]{3,8}$/)) {
            // Add column specific search for SAP code
            d.columns[colNumber].search.value = searchValue;
            // Clear global search to prevent searching other columns
            d.search.value = '';
        }
        // For other searches (like up_to_date, update_available, etc.), don't interfere
    }
}

// Adobe Up-to-Date Status filter
var is_up_to_date_filter = function(colNumber, d) {
    // Look for 'up_to_date' keyword
    if (d.search.value.match(/^up_to_date$/)) {
        // Add column specific search
        d.columns[colNumber].search.value = '= 1';
        // Clear global search
        d.search.value = '';
    }

    // Look for 'update_available' keyword  
    if (d.search.value.match(/^update_available$/)) {
        // Add column specific search
        d.columns[colNumber].search.value = '= 0';
        // Clear global search
        d.search.value = '';
    }

    // Look for 'unknown_status' keyword
    if (d.search.value.match(/^unknown_status$/)) {
        d.columns[colNumber].search.value = 'IS NULL';
        d.search.value = '';
    }
}
