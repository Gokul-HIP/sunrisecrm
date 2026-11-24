<?php
load_css(array(
    "assets/css/content-calendar.css"
));
?>
<div id="page-content" class="page-wrapper clearfix cc-page">
    <div class="cc-wrap" id="cc-app">

        <div class="cc-header">
            <div class="cc-header-text">
                <h1 class="cc-title"><?php echo esc(cc_lang("content_calendar")); ?></h1>
                <p class="cc-subtitle"><?php echo esc(cc_lang("cc_subtitle")); ?></p>
            </div>
            <div class="cc-header-actions">
                <?php if ($perms["add"]) { ?>
                    <button type="button" class="cc-btn" id="cc-import-open" aria-haspopup="dialog">
                        <i data-feather="upload" class="icon-16"></i> <?php echo esc(cc_lang("cc_import")); ?>
                    </button>
                <?php } ?>
                <?php if (!empty($perms["add_column"]) || !empty($perms["admin_manage"])) { ?>
                    <button type="button" class="cc-btn" id="cc-columns-toggle" aria-expanded="false" aria-controls="cc-designer">
                        <i data-feather="columns" class="icon-16"></i> <?php echo esc(cc_lang("cc_columns")); ?>
                    </button>
                <?php } ?>
                <?php if ($perms["add"]) { ?>
                    <a href="#" class="cc-btn cc-btn-primary" id="cc-add-content" role="button">
                        <i data-feather="plus" class="icon-16"></i> <?php echo esc(cc_lang("cc_add_content")); ?>
                    </a>
                <?php } ?>
            </div>
        </div>

        <div class="cc-tabs" id="cc-client-tabs" role="tablist" aria-label="<?php echo esc(cc_lang('cc_clients'), 'attr'); ?>"></div>

        <div id="cc-empty-calendars" class="cc-card cc-empty hide">
            <?php echo esc(cc_lang("cc_no_clients_yet")); ?>
        </div>

        <div id="cc-body" class="hide">

            <div class="cc-tiles" id="cc-tiles"></div>

            <div class="cc-months">
                <button type="button" class="cc-arrow hide" id="cc-months-prev" aria-label="<?php echo esc(cc_lang('cc_previous'), 'attr'); ?>"><i data-feather="chevron-left" class="icon-16"></i></button>
                <div class="cc-month-row" id="cc-month-row"></div>
                <button type="button" class="cc-arrow hide" id="cc-months-next" aria-label="<?php echo esc(cc_lang('cc_next'), 'attr'); ?>"><i data-feather="chevron-right" class="icon-16"></i></button>
            </div>

            <div class="cc-viewbar">
                <h2 class="cc-viewbar-title" id="cc-month-title"></h2>
                <div class="cc-viewbar-controls">
                    <div class="cc-filter-field">
                        <label class="cc-filter-label" for="cc-filter-type"><?php echo esc(cc_lang('cc_type')); ?></label>
                        <select id="cc-filter-type" class="cc-select cc-filter"></select>
                    </div>
                    <div class="cc-filter-field" id="cc-filter-goal-wrap">
                        <label class="cc-filter-label" for="cc-filter-goal"><?php echo esc(cc_lang('cc_goal')); ?></label>
                        <select id="cc-filter-goal" class="cc-select cc-filter"></select>
                    </div>
                    <div class="cc-filter-field">
                        <label class="cc-filter-label" for="cc-filter-status"><?php echo esc(cc_lang('cc_status')); ?></label>
                        <select id="cc-filter-status" class="cc-select cc-filter"></select>
                    </div>
                    <div class="cc-filter-field cc-filter-field-toggle">
                        <span class="cc-filter-label" aria-hidden="true">&nbsp;</span>
                        <div class="cc-toggle-row">
                            <div class="cc-toggle" role="group" aria-label="<?php echo esc(cc_lang('cc_view'), 'attr'); ?>">
                                <button type="button" class="cc-toggle-btn active" data-view="month"><?php echo esc(cc_lang("cc_month")); ?></button>
                                <button type="button" class="cc-toggle-btn" data-view="table"><?php echo esc(cc_lang("cc_table")); ?></button>
                            </div>
                            <button type="button" class="cc-btn cc-undated-btn" id="cc-undated-open" aria-haspopup="dialog"><?php echo esc(sprintf(cc_lang("cc_without_date_n"), 0)); ?></button>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($perms["add_column"]) || !empty($perms["admin_manage"])) { ?>
                <div class="cc-card cc-designer hide" id="cc-designer">
                    <div class="cc-designer-head">
                        <div>
                            <h3 class="cc-card-title"><?php echo esc(cc_lang("cc_column_designer")); ?></h3>
                            <div class="cc-muted"><?php echo esc(cc_lang("cc_column_designer_help")); ?></div>
                        </div>
                        <div class="cc-designer-actions">
                            <button type="button" class="cc-btn" id="cc-copy-columns"><?php echo esc(cc_lang("cc_copy_from_client")); ?></button>
                            <button type="button" class="cc-btn cc-btn-primary" id="cc-add-column"><i data-feather="plus" class="icon-16"></i> <?php echo esc(cc_lang("cc_add_column")); ?></button>
                            <?php if (!empty($perms["admin_manage"])) { ?>
                                <button type="button" class="cc-btn cc-btn-danger-ghost" id="cc-delete-calendar"><?php echo esc(cc_lang("cc_delete_client")); ?></button>
                            <?php } ?>
                        </div>
                    </div>
                    <div class="cc-designer-grid" id="cc-designer-grid"></div>
                </div>
            <?php } ?>

            <div id="cc-month-view" class="cc-layout">
                <div class="cc-grid-card cc-card">
                    <div class="cc-grid-scroll">
                        <div class="cc-grid" id="cc-grid" role="grid"></div>
                    </div>
                </div>
                <aside class="cc-day-panel cc-card" id="cc-day-panel" aria-live="polite"></aside>
            </div>

            <div id="cc-table-view" class="cc-card cc-table-card hide">
                <div class="cc-table-scroll" id="cc-table-wrap"></div>
            </div>
        </div>

    </div>
</div>

<div id="cc-modal-root"></div>

<script type="text/javascript">
    window.CC_CONFIG = <?php echo json_encode($cc_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<?php
load_js(array(
    "assets/js/content-calendar.js"
));
?>
