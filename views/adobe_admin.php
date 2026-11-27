<?php $this->view('partials/head')?>

<style>
@media (min-width: 992px) {
    .adobe-admin-row {
        display: flex;
        flex-wrap: wrap;
    }
    .adobe-admin-row > [class*="col-"] {
        display: flex;
    }
    .adobe-admin-row > [class*="col-"] > .panel {
        width: 100%;
        display: flex;
        flex-direction: column;
    }
    .adobe-admin-row > [class*="col-"] > .panel > .panel-body {
        flex: 1 1 auto;
    }
}
#adobe-admin-status .adobe-admin-path {
    display: inline-block;
    max-width: 100%;
    word-break: break-all;
    white-space: normal;
}
.adobe-admin-action {
    margin-bottom: 14px;
}
.adobe-admin-action:last-child {
    margin-bottom: 0;
}
.adobe-admin-action .help-block {
    margin: 4px 0 8px;
}
</style>

<div class="container">
    <div class="row">
        <div class="col-lg-12">
            <h3 style="margin-top: 0; margin-bottom: 12px;">
                <?= module_icon_html('mr-adobe-icon', '/local/adobe_logo_sq.svg') ?>
                <span data-i18n="adobe.admin.title"></span>
            </h3>
        </div>
    </div>

    <div class="row adobe-admin-row">
        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title" data-i18n="adobe.admin.actions_title"></h3>
                </div>
                <div class="panel-body" id="adobe-admin-actions">
                    <div class="adobe-admin-action">
                        <button id="adobe-admin-validate-mapping" class="btn btn-primary">
                            <i class="fa fa-map"></i> <span data-i18n="adobe.admin.validate_mapping"></span>
                        </button>
                        <p class="help-block" data-i18n="adobe.admin.validate_help"></p>
                    </div>
                    <div class="adobe-admin-action">
                        <button id="adobe-admin-recalculate-years" class="btn btn-info">
                            <i class="fa fa-refresh"></i> <span data-i18n="adobe.admin.recalculate_years"></span>
                        </button>
                        <p class="help-block" data-i18n="adobe.admin.recalculate_help"></p>
                    </div>
                    <div id="adobe-admin-result" class="alert hide" role="alert" style="margin-bottom: 0;"></div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa fa-info-circle"></i>
                        <span data-i18n="adobe.admin.status_title"></span>
                    </h3>
                </div>
                <div class="panel-body">
                    <div id="adobe-admin-status">
                        <p class="text-muted" data-i18n="loading"></p>
                    </div>
                </div>
            </div>
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
            var path = mapping.path || '';
            var pathHtml = path
                ? '<code class="adobe-admin-path">' + esc(path) + '</code>'
                : '—';

            var statusRows = '<table class="table table-striped table-condensed" style="margin-bottom: 0;"><tbody>';
            statusRows += '<tr><th>' + esc(i18n.t('adobe.admin.mapping_file')) + '</th><td>' + pathHtml + '</td></tr>';
            statusRows += '<tr><th>' + esc(i18n.t('adobe.admin.mapping_valid')) + '</th><td>' +
                (mapping.success
                    ? '<span class="label label-success">' + esc(i18n.t('yes')) + '</span>'
                    : '<span class="label label-danger">' + esc(i18n.t('no')) + '</span>') +
                '</td></tr>';
            statusRows += '<tr><th>' + esc(i18n.t('adobe.admin.mapped_apps')) + '</th><td>' + esc(mapping.app_count || 0) + '</td></tr>';
            statusRows += '<tr><th>' + esc(i18n.t('adobe.admin.name_variations')) + '</th><td>' + esc(mapping.variation_count || 0) + '</td></tr>';
            statusRows += '<tr><th>' + esc(i18n.t('adobe.admin.last_modified')) + '</th><td>' + esc(data.mapping_mtime || '—') + '</td></tr>';
            if (!mapping.success && mapping.error) {
                statusRows += '<tr><th>' + esc(i18n.t('error')) + '</th><td><span class="text-danger">' + esc(mapping.error) + '</span></td></tr>';
            }
            statusRows += '</tbody></table>';
            $status.html(statusRows);
        }).fail(function() {
            $status.html('<p class="text-danger">' + esc(i18n.t('error.loading')) + '</p>');
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
