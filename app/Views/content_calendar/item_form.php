<?php
load_css(array(
    "assets/css/content-calendar.css"
));

$is_edit = ($item && $item->id) ? true : false;
$heading = $is_edit ? cc_lang("cc_edit_content") : cc_lang("cc_add_content");
$submit_label = $is_edit ? cc_lang("cc_save_changes") : cc_lang("cc_add_to_calendar");

//preview sample
$preview_type = isset($form_values["type"]) ? $form_values["type"] : "";
$preview_title = isset($form_values["title"]) ? $form_values["title"] : "";
$preview_date = !empty($form_values["date"]) ? $form_values["date"] : "";
?>
<div id="page-content" class="page-wrapper clearfix cc-page">
    <div class="cc-wrap">

        <a href="<?php echo esc($back_url, 'attr'); ?>" class="cc-back"><i data-feather="arrow-left" class="icon-16"></i> <?php echo esc(cc_lang("cc_back_to_calendar")); ?></a>

        <div class="cc-header">
            <div class="cc-header-text">
                <h1 class="cc-title"><?php echo esc($heading); ?></h1>
                <p class="cc-subtitle"><?php echo esc($calendar->name); ?></p>
            </div>
        </div>

        <div class="cc-form-layout">
            <div class="cc-card cc-form-card">
                <form id="cc-item-form" novalidate autocomplete="off">
                    <input type="hidden" name="id" value="<?php echo $is_edit ? (int) $item->id : 0; ?>">
                    <input type="hidden" name="calendar_id" value="<?php echo (int) $calendar->id; ?>">

                    <div class="cc-form-error" id="cc-form-error" role="alert"></div>

                    <div class="cc-form-grid">
                    <?php
                    foreach ($columns as $column) {
                        $key = $column->field_key;
                        $type = $column->input_type;
                        $value = isset($form_values[$key]) ? $form_values[$key] : "";
                        $is_required = ($column->is_core && ($key === "date" || $key === "title"));
                        $field_id = "cc-f-" . $key;
                        $name = "f[" . $key . "]";
                        $is_option_column = ($type === "select" || $type === "multi");
                        ?>
                        <?php $is_wide = ($type === "long" || $type === "multi"); ?>
                        <div class="cc-field<?php echo $is_wide ? ' cc-field-wide' : ''; ?>" data-field="<?php echo esc($key, 'attr'); ?>">
                            <label class="cc-label" for="<?php echo esc($field_id, 'attr'); ?>"><?php echo esc($column->label); ?><?php if ($is_required) { ?><span class="cc-required" aria-hidden="true">*</span><?php } ?></label>

                            <?php if ($type === "long") { ?>
                                <textarea rows="3" class="cc-input cc-input-full" id="<?php echo esc($field_id, 'attr'); ?>" name="<?php echo esc($name, 'attr'); ?>" data-key="<?php echo esc($key, 'attr'); ?>" maxlength="10000"><?php echo esc($value); ?></textarea>

                            <?php } else if ($type === "select") { ?>
                                <select class="cc-input cc-input-full cc-select-field" id="<?php echo esc($field_id, 'attr'); ?>" name="<?php echo esc($name, 'attr'); ?>" data-key="<?php echo esc($key, 'attr'); ?>">
                                    <option value=""><?php echo esc(cc_lang("cc_select")); ?></option>
                                    <?php foreach ($column->options_list as $option) { ?>
                                        <option value="<?php echo esc($option, 'attr'); ?>" <?php echo ((string) $value === (string) $option) ? "selected" : ""; ?>><?php echo esc($option); ?></option>
                                    <?php } ?>
                                </select>

                            <?php } else if ($type === "multi") { ?>
                                <div class="cc-multi" id="<?php echo esc($field_id, 'attr'); ?>" data-key="<?php echo esc($key, 'attr'); ?>" role="group" aria-label="<?php echo esc($column->label, 'attr'); ?>">
                                    <?php
                                    $selected_list = is_array($value) ? $value : array();
                                    foreach ($column->options_list as $option) {
                                        ?>
                                        <label class="cc-chip-opt">
                                            <input type="checkbox" name="<?php echo esc($name, 'attr'); ?>[]" value="<?php echo esc($option, 'attr'); ?>" <?php echo in_array($option, $selected_list, true) ? "checked" : ""; ?>>
                                            <span class="cc-chip-text"><?php echo esc($option); ?></span>
                                            <?php if ($can_manage_options) { ?>
                                                <button type="button" class="cc-chip-x" title="<?php echo esc(cc_lang('cc_delete_option'), 'attr'); ?>" aria-label="<?php echo esc(cc_lang('cc_delete_option'), 'attr'); ?>" data-option="<?php echo esc($option, 'attr'); ?>">&times;</button>
                                            <?php } ?>
                                        </label>
                                    <?php } ?>
                                </div>

                            <?php } else if ($type === "check") { ?>
                                <label class="cc-check">
                                    <input type="checkbox" id="<?php echo esc($field_id, 'attr'); ?>" name="<?php echo esc($name, 'attr'); ?>" value="1" data-key="<?php echo esc($key, 'attr'); ?>" <?php echo $value ? "checked" : ""; ?>>
                                    <span><?php echo esc(cc_lang("yes")); ?></span>
                                </label>

                            <?php } else if ($type === "file") { ?>
                                <div class="cc-file">
                                    <input type="text" class="cc-input cc-input-full cc-file-value" id="<?php echo esc($field_id, 'attr'); ?>" name="<?php echo esc($name, 'attr'); ?>" data-key="<?php echo esc($key, 'attr'); ?>" value="<?php echo esc($value, 'attr'); ?>" placeholder="https://" maxlength="500">
                                    <label class="cc-btn cc-file-pick">
                                        <i data-feather="upload" class="icon-16"></i> <?php echo esc(cc_lang("cc_upload")); ?>
                                        <input type="file" class="cc-file-input" hidden>
                                    </label>
                                </div>
                                <div class="cc-muted cc-file-hint"><?php echo esc(cc_lang("cc_file_hint")); ?></div>

                            <?php } else {
                                $html_type = "text";
                                $placeholder = "";
                                if ($type === "date") {
                                    $html_type = "date";
                                } else if ($type === "link") {
                                    $placeholder = "https://";
                                } else if ($type === "number") {
                                    $html_type = "text";
                                }
                                ?>
                                <input type="<?php echo $html_type; ?>" class="cc-input cc-input-full" id="<?php echo esc($field_id, 'attr'); ?>" name="<?php echo esc($name, 'attr'); ?>" data-key="<?php echo esc($key, 'attr'); ?>" value="<?php echo esc(is_array($value) ? "" : (string) $value, 'attr'); ?>" <?php echo $placeholder ? "placeholder='" . esc($placeholder, 'attr') . "'" : ""; ?> <?php echo $type === "number" ? "inputmode='decimal'" : ""; ?> <?php echo ($key === "date" && $column->is_core && !empty($no_date)) ? "disabled" : ""; ?> maxlength="<?php echo ($type === "link" ? 1000 : 500); ?>">
                            <?php } ?>

                            <?php if ($is_option_column && $can_manage_options) { ?>
                                <div class="cc-addopt" data-column-id="<?php echo (int) $column->id; ?>" data-key="<?php echo esc($key, 'attr'); ?>" data-type="<?php echo esc($type, 'attr'); ?>">
                                    <input type="text" class="cc-addopt-input" maxlength="60" placeholder="<?php echo esc(sprintf(cc_lang('cc_new_option_short'), mb_strtolower($column->label)), 'attr'); ?>" aria-label="<?php echo esc(sprintf(cc_lang('cc_add_new_option'), $column->label), 'attr'); ?>">
                                    <div class="cc-addopt-actions">
                                        <button type="button" class="cc-btn cc-addopt-btn">+ <?php echo esc(cc_lang("add")); ?></button>
                                        <?php if ($type === "select") { ?>
                                            <button type="button" class="cc-btn cc-btn-danger-ghost cc-delopt-btn" disabled><?php echo esc(cc_lang("cc_delete_option")); ?></button>
                                        <?php } ?>
                                    </div>
                                </div>
                            <?php } ?>

                            <?php if ($key === "date" && $column->is_core) { ?>
                                <label class="cc-check cc-no-date">
                                    <input type="checkbox" id="cc-no-date" name="no_date" value="1" <?php echo !empty($no_date) ? "checked" : ""; ?>>
                                    <span><?php echo esc(cc_lang("cc_no_date_yet")); ?></span>
                                </label>
                                <div class="cc-muted cc-no-date-hint"><?php echo esc(cc_lang("cc_no_date_hint")); ?></div>
                                <?php if (!empty($form_values["_src_date_text"]) && is_scalar($form_values["_src_date_text"])) { ?>
                                    <div class="cc-muted cc-no-date-hint"><?php echo esc(sprintf(cc_lang("cc_undated_from_sheet"), (string) $form_values["_src_date_text"])); ?></div>
                                <?php } ?>
                            <?php } ?>

                            <div class="cc-field-error" role="alert" data-error-for="<?php echo esc($key, 'attr'); ?>"></div>
                        </div>
                    <?php } ?>
                    </div>

                    <div class="cc-form-actions">
                        <button type="submit" class="cc-btn cc-btn-primary" id="cc-submit"><?php echo esc($submit_label); ?></button>
                        <a href="<?php echo esc($back_url, 'attr'); ?>" class="cc-btn"><?php echo esc(cc_lang("cancel")); ?></a>
                    </div>
                </form>
            </div>

            <aside class="cc-card cc-preview-card">
                <h3 class="cc-card-title"><?php echo esc(cc_lang("cc_how_it_appears")); ?></h3>
                <div class="cc-preview-date" id="cc-preview-date"><?php echo esc($preview_date); ?></div>
                <div class="cc-preview-chip-wrap"><span class="cc-chip" id="cc-preview-chip"></span></div>
                <div class="cc-muted"><?php echo esc(cc_lang("cc_preview_help")); ?></div>
            </aside>
        </div>
    </div>
</div>

<script type="text/javascript">
    window.CC_ITEM_FORM = <?php echo json_encode($cc_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<?php
load_js(array(
    "assets/js/content-calendar.js"
));
?>
