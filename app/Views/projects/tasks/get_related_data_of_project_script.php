<script>
    $(document).ready(function () {
        var projectRelatedDataXhr = null;

        //load all related data of the selected project
        $("#project_id").select2().on("change", function () {
            var projectId = $(this).val();
            if (projectRelatedDataXhr && projectRelatedDataXhr.abort) {
                projectRelatedDataXhr.abort();
            }

            if (projectId) {
                var prevAssignedTo = $("#assigned_to").val();
                var prevCollaborators = $("#collaborators").val() ? $("#collaborators").val().split(",") : [];

                $('#milestone_id').off(".select2");
                if ($('#milestone_id').data("select2")) { $('#milestone_id').select2("destroy"); }
                $("#milestone_id").hide();
                $('#assigned_to').off(".select2");
                if ($('#assigned_to').data("select2")) { $('#assigned_to').select2("destroy"); }
                $("#assigned_to").hide();
                $('#collaborators').off(".select2");
                if ($('#collaborators').data("select2")) { $('#collaborators').select2("destroy"); }
                $("#collaborators").hide();
                $('#project_labels').off(".select2");
                if ($('#project_labels').data("select2")) { $('#project_labels').select2("destroy"); }
                $("#project_labels").hide();

                appLoader.show({container: "#dropdown-apploader-section", zIndex: 1});
                projectRelatedDataXhr = $.ajax({
                    url: "<?php echo get_uri('projects/get_all_related_data_of_selected_project') ?>" + "/" + projectId,
                    dataType: "json",
                    success: function (result) {
                        if ($("#project_id").val() !== String(projectId)) {
                            return;
                        }

                        $("#milestone_id").show().val("");
                        $('#milestone_id').select2({data: result.milestones_dropdown});

                        // Keep previous assignee if still a member of the new project
                        var keepAssignee = "";
                        if (prevAssignedTo && result.assign_to_dropdown) {
                            for (var i = 0; i < result.assign_to_dropdown.length; i++) {
                                if (String(result.assign_to_dropdown[i].id) === String(prevAssignedTo) && prevAssignedTo !== "") {
                                    keepAssignee = prevAssignedTo;
                                    break;
                                }
                            }
                        }
                        $("#assigned_to").show().val(keepAssignee);
                        $('#assigned_to').select2({data: result.assign_to_dropdown});
                        if (keepAssignee) {
                            $('#assigned_to').select2("val", keepAssignee);
                        }

                        // Keep previous collaborators if still members of the new project
                        var keepCollaborators = [];
                        if (prevCollaborators && prevCollaborators.length && result.collaborators_dropdown) {
                            var validCollabIds = {};
                            for (var j = 0; j < result.collaborators_dropdown.length; j++) {
                                validCollabIds[String(result.collaborators_dropdown[j].id)] = true;
                            }
                            for (var k = 0; k < prevCollaborators.length; k++) {
                                if (validCollabIds[String(prevCollaborators[k])]) {
                                    keepCollaborators.push(prevCollaborators[k]);
                                }
                            }
                        }
                        $("#collaborators").show().val(keepCollaborators.join(","));
                        $('#collaborators').select2({multiple: true, data: result.collaborators_dropdown});
                        if (keepCollaborators.length) {
                            $('#collaborators').select2("val", keepCollaborators);
                        }

                        $("#project_labels").show().val("");
                        $('#project_labels').select2({multiple: true, data: result.label_suggestions});
                        appLoader.hide();
                    },
                    error: function (xhr, status) {
                        if (status !== "abort") {
                            appLoader.hide();
                        }
                    }
                });
            } else {
                $('#milestone_id').off(".select2");
                if ($('#milestone_id').data("select2")) { $('#milestone_id').select2("destroy"); }
                $("#milestone_id").show().val("");
                $('#milestone_id').select2({data: [{id: "", text: "-"}]});

                $('#assigned_to').off(".select2");
                if ($('#assigned_to').data("select2")) { $('#assigned_to').select2("destroy"); }
                $("#assigned_to").show().val("");
                $('#assigned_to').select2({data: [{id: "", text: "-"}]});

                $('#collaborators').off(".select2");
                if ($('#collaborators').data("select2")) { $('#collaborators').select2("destroy"); }
                $("#collaborators").show().val("");
                $('#collaborators').select2({multiple: true, data: []});

                $('#project_labels').off(".select2");
                if ($('#project_labels').data("select2")) { $('#project_labels').select2("destroy"); }
                $("#project_labels").show().val("");
                $('#project_labels').select2({multiple: true, data: []});

                appLoader.hide();
            }
        });

        //intialized select2 dropdown for first time
        $("#project_labels").select2({multiple: true, data: <?php echo json_encode($label_suggestions); ?>});
        $("#collaborators").select2({multiple: true, data: <?php echo json_encode($collaborators_dropdown); ?>});
        $('#milestone_id').select2({data: <?php echo json_encode($milestones_dropdown); ?>});
        $('#assigned_to').select2({data: <?php echo json_encode($assign_to_dropdown); ?>});
    });
</script>