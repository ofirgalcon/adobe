<div id="adobe-tab"></div>
<div id="lister" style="font-size: large; float: right;">
    <a href="/show/listing/adobe/adobe" data-toggle="tooltip" data-placement="bottom" data-i18n="[title]adobe.list_tooltip">
        <i class="btn btn-default tab-btn fa fa-list-alt"></i>
    </a>
</div>
<div id="report_btn" style="font-size: large; float: right;">
    <a href="/show/report/adobe/adobe" data-toggle="tooltip" data-placement="bottom" data-i18n="[title]adobe.report_tooltip">
        <i class="btn btn-default tab-btn fa fa-bar-chart-o"></i>
    </a>
</div>
<div id="mapping_health_btn" style="font-size: large; float: right; margin-right: 10px;">
    <a href="#" id="adobe-mapping-health-btn" data-toggle="tooltip" data-placement="bottom" data-i18n="[title]adobe.mapping_health_tooltip">
        <i class="btn btn-default tab-btn fa fa-map"></i>
    </a>
</div>
<h2><?= module_icon_html('mr-adobe-icon', '/local/adobe_logo_sq.svg') ?> <span data-i18n="adobe.listing.title"></span></h2>

<div id="adobe-msg" data-i18n="loading"></div>
<div id="adobe-table-view" class="hide">
    <table class="adobe table table-striped table-condensed table-bordered" id="adobe-tab-table">
        <thead>
            <tr>
                <th data-i18n="adobe.app_name_short"></th>
                <th data-i18n="adobe.sapcode"></th>
                <th data-i18n="adobe.base_version"></th>
                <th data-i18n="adobe.year_edition"></th>
                <th data-i18n="adobe.installed_version"></th>
                <th data-i18n="adobe.latest_version"></th>
                <th data-i18n="adobe.is_up_to_date"></th>
                <th data-i18n="adobe.description"></th>
            </tr>
        </thead>
        <tbody>
        </tbody>
    </table>
</div>

<script src="<?=url('module/adobe/js/adobe')?>"></script>
<script>
// Adobe Tab - Security hardened against XSS attacks
// All user data is properly escaped using .text() method
$(document).on('appReady', function(e, lang) {
    $('#lister [data-toggle="tooltip"], #report_btn [data-toggle="tooltip"], #mapping_health_btn [data-toggle="tooltip"]').tooltip({
        placement: 'bottom',
        container: 'body'
    });

    $('#adobe-mapping-health-btn').off('click').on('click', function(event) {
        event.preventDefault();

        if (!confirm(i18n.t('adobe.mapping_health_confirm'))) {
            return;
        }

        $.getJSON(appUrl + '/module/adobe/get_mapping_health', function(data) {
            if (data && data.success) {
                alert(
                    i18n.t('adobe.mapping_health_success') + "\n" +
                    i18n.t('adobe.mapping_health_apps') + ': ' + (data.app_count || 0) + "\n" +
                    i18n.t('adobe.mapping_health_variations') + ': ' + (data.variation_count || 0)
                );
            } else {
                alert(i18n.t('adobe.mapping_health_failed') + ': ' + ((data && data.error) ? data.error : i18n.t('error')));
            }
        }).fail(function(xhr){
            if (xhr.status === 403) {
                alert(i18n.t('adobe.mapping_health_admin_only'));
            } else {
                alert(i18n.t('adobe.mapping_health_failed') + ': ' + i18n.t('error.loading'));
            }
        });
    });

    // Load data via AJAX first
    $.getJSON(appUrl + '/module/adobe/get_tab_data/' + serialNumber, function(data){
        // Handle both data.msg format and direct array format
        var adobeData = data.msg || data;
        
        // Check if we have data
        if(!adobeData || !adobeData.length){
            $('#adobe-msg').text(i18n.t('no_data'));
            $('#adobe-cnt').empty().hide()
                .removeClass('alert-success alert-warning alert-danger alert-info')
                .removeAttr('data-mr-badge-pending')
                .attr('data-mr-badge-from-tab', '1');
            return;
        }
        
        // Hide loading message and show table
        $('#adobe-msg').addClass('hide');
        $('#adobe-table-view').removeClass('hide');
        
        var tbody = $('#adobe-tab-table tbody');
        tbody.empty();
        
        // Process each Adobe app record
        $.each(adobeData, function(index, app){
            // Validate app object structure to prevent XSS
            if (!app || typeof app !== 'object') {
                return; // Skip invalid entries
            }
            
            var row = $('<tr>');
            // App name + local icon (mr.appIcon). Resolve SAP placeholders for older rows.
            var appName = (typeof resolveAdobeAppName === 'function')
                ? resolveAdobeAppName(app.app_name || '', app.sapcode || '')
                : (app.app_name || '');
            var nameCell = $('<td>');
            if (typeof mr.appIcon === 'function' && mr.appIconsEnabled()) {
                nameCell.append(mr.appIcon(appName, appName));
            } else {
                nameCell.text(appName);
            }
            row.append(nameCell);
            row.append($('<td>').text(app.sapcode || ''));
            row.append($('<td>').text(app.base_version || ''));
            row.append($('<td>').text(app.year_edition || ''));
            row.append($('<td>').text(app.installed_version || ''));
            row.append($('<td>').text(app.latest_version || ''));
            
            // Format the up-to-date status with color coding
            var statusCell = $('<td>');
            var statusText = '';
            var statusClass = '';
            
            if (app.is_up_to_date === '1' || app.is_up_to_date === 1) {
                statusText = i18n.t('yes');
                statusClass = 'label-success';
            } else if (app.is_up_to_date === '0' || app.is_up_to_date === 0) {
                statusText = i18n.t('no');
                statusClass = 'label-danger';
            } else {
                statusText = i18n.t('adobe.unknown_status');
                statusClass = 'label-warning';
            }
            
            // Create status element safely without HTML injection
            var statusSpan = $('<span>')
                .addClass('label')
                .addClass(statusClass)
                .text(statusText);
            statusCell.append(statusSpan);
            row.append(statusCell);
            
            row.append($('<td>').text(app.description || ''));
            tbody.append(row);
        });
        
        // Initialize DataTable after populating the table
        $('.adobe').dataTable({
            "bServerSide": false,
            "aaSorting": [[0,'asc']],
            "fnDrawCallback": function( oSettings ) {
                // Safely set the count without HTML injection; hide literal 0.
                var n = oSettings.fnRecordsTotal();
                var $badge = $('#adobe-cnt')
                    .removeClass('alert-success alert-warning alert-danger alert-info')
                    .removeAttr('data-mr-badge-pending')
                    .attr('data-mr-badge-from-tab', '1');
                if (n > 0) {
                    $badge.text(n).addClass('alert-info').css('display', '');
                } else {
                    $badge.empty().hide();
                }
            }
        });
    })
    .fail(function(){
        $('#adobe-msg').text(i18n.t('error.loading'));
        // Fail ≠ zero apps: show ? so transient errors don't wipe a good prefetch.
        $('#adobe-cnt')
            .text('?')
            .removeClass('alert-success alert-warning alert-danger alert-info')
            .addClass('alert-warning')
            .removeAttr('data-mr-badge-pending data-mr-badge-from-tab')
            
            .css('display', '');
    });
});
</script>
