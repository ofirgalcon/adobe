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