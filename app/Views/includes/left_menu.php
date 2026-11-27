<div class="sidebar sidebar-off">
    <?php
    $user = $login_user->id;
    $dashboard_link = get_uri("dashboard");
    $user_dashboard = get_setting("user_" . $user . "_dashboard");
    if ($user_dashboard) {
        $dashboard_link = get_uri("dashboard/view/" . $user_dashboard);
    }
    ?>
    <a class="sidebar-toggle-btn hide" href="#">
        <i data-feather="menu" class="icon mt-1 text-off"></i>
    </a>

    <a class="sidebar-brand brand-logo" href="<?php echo $dashboard_link; ?>"><img class="dashboard-image" src="<?php echo get_logo_url(); ?>" /></a>
    <a class="sidebar-brand brand-logo-mini" href="<?php echo $dashboard_link; ?>"><img class="dashboard-image" src="<?php echo get_favicon_url(); ?>" /></a>

    <div class="sidebar-scroll">
        <ul id="sidebar-menu" class="sidebar-menu">
            <?php
            if (!$is_preview) {
                $sidebar_menu = get_active_menu($sidebar_menu);
            }

            foreach ($sidebar_menu as $main_menu) {
                $main_menu_name = get_array_value($main_menu, "name");
                if (!$main_menu_name) {
                    continue;
                }

                $is_custom_menu_item = get_array_value($main_menu, "is_custom_menu_item");
                $open_in_new_tab = get_array_value($main_menu, "open_in_new_tab");
                $url = get_array_value($main_menu, "url");
                $class = get_array_value($main_menu, "class");
                $custom_class = get_array_value($main_menu, "custom_class");
                $submenu = get_array_value($main_menu, "submenu");

                $expend_class = $submenu ? " expand " : "";
                $active_class = get_array_value($main_menu, "is_active_menu") ? "active" : "";

                $submenu_open_class = "";
                if ($expend_class && $active_class) {
                    $submenu_open_class = " open ";
                }

                if ($is_custom_menu_item) {
                    $language_key = get_array_value($main_menu, "language_key");
                    if ($language_key) {
                        $main_menu_name = app_lang($language_key);
                    }
                } else {
                    $main_menu_name = app_lang($main_menu_name);
                }

                $badge = get_array_value($main_menu, "badge");
                $badge_class = get_array_value($main_menu, "badge_class");
                $target = ($is_custom_menu_item && $open_in_new_tab) ? "target='_blank'" : "";
                ?>

                <li class="<?php echo $active_class . " " . $expend_class . " " . $submenu_open_class . " "; ?> main">
                    <a <?php echo $target; ?> href="<?php echo $is_custom_menu_item ? $url : get_uri($url); ?>">
                        <i data-feather="<?php echo $class; ?>" class="icon"></i>
                        <span class="menu-text <?php echo $custom_class; ?>"><?php echo $main_menu_name; ?></span>
                        <?php
                        if ($badge) {
                            echo "<span class='badge rounded-pill $badge_class'>$badge</span>";
                        }
                        ?>
                    </a>
                    <?php
                    if ($submenu) {
                        echo "<ul>";
                        foreach ($submenu as $s_menu) {
                            $s_menu_name = get_array_value($s_menu, "name");
                            if (!$s_menu_name) {
                                continue;
                            }

                            $is_custom_menu_item = get_array_value($s_menu, "is_custom_menu_item");
                            $url = get_array_value($s_menu, "url");

                            if ($is_custom_menu_item) {
                                $language_key = get_array_value($s_menu, "language_key");
                                if ($language_key) {
                                    $s_menu_name = app_lang($language_key);
                                }
                            } else if (get_array_value($s_menu, "is_team_group")) {
                                $s_menu_name = get_array_value($s_menu, "name");
                            } else {
                                $s_menu_name = app_lang($s_menu_name);
                            }

                            if ($s_menu_name) {
                                $open_in_new_tab = get_array_value($s_menu, "open_in_new_tab");
                                $sub_menu_target = ($is_custom_menu_item && $open_in_new_tab) ? "target='_blank'" : "";
                                $data_act = get_array_value($s_menu, "data_act");
                                $data_action_url = get_array_value($s_menu, "data_action_url");
                                $data_title = get_array_value($s_menu, "data_title");
                                $extra_attr = "";
                                if ($data_act) {
                                    $extra_attr = " data-act='$data_act' data-action-url='$data_action_url' data-title='$data_title'";
                                }
                                $href = $data_act ? "#" : ($is_custom_menu_item ? $url : get_uri($url));
                                $s_icon = get_array_value($s_menu, "class") ?: "minus";
                                $sub_active = "";
                                if (!$data_act && isset($current_url)) {
                                    $full_url = get_uri($url);
                                    if ($full_url === $current_url || $url === $current_url) {
                                        $sub_active = "active";
                                    }
                                }
                                ?>
                            <li class="<?php echo $sub_active; ?>">
                                <a <?php echo $sub_menu_target . $extra_attr; ?> href="<?php echo $href; ?>">
                                    <i data-feather='<?php echo $s_icon; ?>' width='12'></i>
                                    <span><?php echo $s_menu_name; ?></span>
                                    <?php
                                    $s_badge = get_array_value($s_menu, "badge");
                                    $s_badge_class = get_array_value($s_menu, "badge_class");
                                    if ($s_badge) {
                                        echo "<span class='badge rounded-pill $s_badge_class'>$s_badge</span>";
                                    }
                                    ?>
                                </a>
                            </li>
                            <?php
                        }
                    }
                    echo "</ul>";
                }
                ?>
                </li>
                <?php
            }
            ?>
        </ul>
    </div>
</div><!-- sidebar menu end -->

<script type='text/javascript'>
    feather.replace();
</script>