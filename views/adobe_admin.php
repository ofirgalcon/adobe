<?php $this->view('partials/head')?>

<div class="container">
    <div class="row">
        <div class="col-lg-7">
            <h3><i class="fa fa-creative-commons"></i> <span data-i18n="adobe.admin.title"></span></h3>
            <p data-i18n="adobe.admin.description"></p>

            <div class="form-group" style="margin-top: 15px;">
                <button id="adobe-admin-validate-mapping" class="btn btn-primary">
                    <i class="fa fa-stethoscope"></i> <span data-i18n="adobe.admin.validate_mapping"></span>
                </button>
                <button id="adobe-admin-recalculate-years" class="btn btn-default" style="margin-left:8px;">
                    <i class="fa fa-refresh"></i> <span data-i18n="adobe.admin.recalculate_years"></span>
                </button>
            </div>

            <div id="adobe-admin-result" class="alert hide" role="alert"></div>
        </div>
        <div class="col-lg-5">
            <h3><i class="fa fa-info-circle"></i> <span data-i18n="adobe.admin.status_title"></span></h3>
            <div id="adobe-admin-status"></div>
        </div>
    </div>
</div>

<script>
(function(){
    var $status = $('#adobe-admin-status');
    var $result = $('#adobe-admin-result');

    function showResult(type, message) {
        $result.removeClass('hide alert-success alert-danger alert-warning alert-info');
        $result.addClass('alert-' + type).text(message);
    }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function loadStatus() {
        $.getJSON(appUrl + '/module/adobe/get_admin_data', function(data) {
            var mapping = data.mapping_health || {};
            var statusRows = '<table class="table table-striped"><tbody>';
            statusRows += '<tr><th>' + esc(i18n.t('adobe.admin.mapping_file')) + '</th><td><code>' + esc(mapping.path || '-') + '</code></td></tr>';
            statusRows += '<tr><th>' + esc(i18n.t('adobe.admin.mapping_valid')) + '</th><td>' +
                (mapping.success ? '<span class="label label-success">' + esc(i18n.t('yes')) + '</span>' : '<span class="label label-danger">' + esc(i18n.t('no')) + '</span>') +
                '</td></tr>';
            statusRows += '<tr><th>' + esc(i18n.t('adobe.admin.mapped_apps')) + '</th><td>' + esc(mapping.app_count || 0) + '</td></tr>';
            statusRows += '<tr><th>' + esc(i18n.t('adobe.admin.name_variations')) + '</th><td>' + esc(mapping.variation_count || 0) + '</td></tr>';
            statusRows += '<tr><th>' + esc(i18n.t('adobe.admin.last_modified')) + '</th><td>' + esc(data.mapping_mtime || '-') + '</td></tr>';
            if (!mapping.success && mapping.error) {
                statusRows += '<tr><th>' + esc(i18n.t('error')) + '</th><td><span class="text-danger">' + esc(mapping.error) + '</span></td></tr>';
            }
            statusRows += '</tbody></table>';
            $status.html(statusRows);
        }).fail(function() {
            $status.text(i18n.t('error.loading'));
        });
    }

    $('#adobe-admin-validate-mapping').on('click', function() {
        $.getJSON(appUrl + '/module/adobe/get_mapping_health', function(data) {
            if (data.success) {
                showResult('success', i18n.t('adobe.mapping_health_success'));
            } else {
                showResult('danger', i18n.t('adobe.mapping_health_failed') + ': ' + (data.error || i18n.t('error')));
            }
            loadStatus();
        }).fail(function(xhr){
            if (xhr.status === 403) {
                showResult('warning', i18n.t('adobe.mapping_health_admin_only'));
            } else {
                showResult('danger', i18n.t('adobe.mapping_health_failed') + ': ' + i18n.t('error.loading'));
            }
        });
    });

    $('#adobe-admin-recalculate-years').on('click', function() {
        if (!confirm(i18n.t('adobe.admin.recalculate_confirm'))) {
            return;
        }

        $.ajax({
            url: appUrl + '/module/adobe/force_update_year_editions',
            method: 'POST',
            dataType: 'json'
        }).done(function(data) {
            if (data && data.success) {
                showResult('info', data.message || i18n.t('adobe.admin.recalculate_done'));
            } else {
                showResult('danger', (data && data.error) ? data.error : i18n.t('error'));
            }
        }).fail(function() {
            showResult('danger', i18n.t('error.loading'));
        });
    });

    $(document).on('appReady', function() {
        loadStatus();
    });
})();
</script>

<?php $this->view('partials/foot'); ?>
