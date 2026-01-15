<?php
  
/**
 * Shortcode: [learndash_client_report include_mentor_group="0"]
 * - Admins (manage_options) see ALL LearnDash groups.
 * - Non-admins see only the group(s) tied to their client in ACF options.
 * - Uses MP_Clients::get_user_client_id(), ::get_client_ld_group(), ::get_client_mentor_ld_group()
 */
function mpro_learndash_client_report_shortcode( $atts ) {
    if ( ! function_exists( 'learndash_get_group_courses_list' ) ) {
        return '<p>LearnDash is not active.</p>';
    }

    $atts = shortcode_atts( [
        'include_mentor_group' => '1', // "1" to also include the mentors LD group if configured
    ], $atts, 'learndash_client_report' );

    $is_admin_view = current_user_can( 'manage_options' );

    // ADMIN: list all groups, unchanged behavior
    if ( $is_admin_view ) {
        $groups = get_posts( [
            'post_type'      => 'groups',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ] );
        $scope_text = 'Admin view: showing all groups.';
    } else {
        // NON-ADMIN: use client config to find group IDs
        $client_id = '';
        if ( function_exists( 'mp_get_user_context' ) ) {
            $ctx = mp_get_user_context();
            if ( is_array( $ctx ) && ! empty( $ctx['client_id'] ) ) {
                $client_id = (string) $ctx['client_id'];
            }
        }
        if ( $client_id === '' ) {
            return '<p>No client context found.</p>';
        }

        // Pull group IDs from MP_Clients helpers (ACF options)
        $groups_ids = [];
        $primary_gid = get_client_ld_group( $client_id );
        if ( $primary_gid ) {
            $groups_ids[] = (int) $primary_gid;
        }
        if ( $atts['include_mentor_group'] === '1' ) {
            $mentor_gid = get_client_mentor_ld_group( $client_id );
            if ( $mentor_gid ) {
                $groups_ids[] = (int) $mentor_gid;
            }
        }

        // Also include groups the user is directly enrolled in (e.g., program manager group)
        $user_id = get_current_user_id();
        if ( $user_id && function_exists( 'learndash_get_users_group_ids' ) ) {
            $user_groups = learndash_get_users_group_ids( $user_id );
            if ( ! empty( $user_groups ) && is_array( $user_groups ) ) {
                $groups_ids = array_unique( array_merge( $groups_ids, array_map( 'intval', $user_groups ) ) );
            }
        }

        if ( empty( $groups_ids ) ) {
            // Helpful hint for setup issues
            return '<p>No LearnDash group configured for client: <strong>' . esc_html( $client_id ) . '</strong>.</p>';
        }

        // Fetch only those groups
        $groups = get_posts( [
            'post_type'      => 'groups',
            'post__in'       => $groups_ids,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'post__in', // keep configured order
            'no_found_rows'  => true,
        ] );

        $scope_text = 'Client: ' . esc_html( $client_id ) . ' Role: ' . json_encode($ctx['roles']);
    }

    ob_start(); ?>
    <div class="wrap mpro-ld-client-report">
        <h2>LearnDash Group Report</h2>
        <?php if ( $is_admin_view ) {
                echo '<p><em><?php echo $scope_text; ?></em></p>'; }
        ?>

        <table class="widefat striped">
            <thead>
            <tr>
                <th>Group Name</th>
                <th>Courses</th>
            </tr>
            </thead>
            <tbody>
            <?php if ( $groups ) :
                foreach ( $groups as $group ) :
                    $course_ids = learndash_get_group_courses_list( $group->ID );
                    $html = '<ol>';
                    if ( ! empty( $course_ids ) && is_array( $course_ids ) ) {
                        foreach ( $course_ids as $course_id ) {
                            $course_url = get_permalink( $course_id );
                            $course_title = get_the_title( $course_id );
                            $html .= '<li><a href="' . esc_url( $course_url ) . '">' . esc_html( $course_title ) . '</a></li>';
                        }
                    } else {
                        $html .= '<li><em>No courses assigned</em></li>';
                    }
                    $html .= '</ol>';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( get_the_title( $group ) ); ?></strong>
                            <?php if ( $is_admin_view ) { ?>
                                <br><a target="_blank" rel="noopener"
                               href="<?php echo esc_url( admin_url( 'post.php?post=' . $group->ID . '&action=edit&currentTab=learndash_group_courses' ) ); ?>">
                                Update Course Assignments
                                </a>
                            <?php } ?>
                        </td>
                        <td><?php echo $html; // built above ?></td>
                    </tr>
                <?php endforeach;
            else : ?>
                <tr><td colspan="2">No LearnDash groups found in this scope.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'learndash_client_report', 'mpro_learndash_client_report_shortcode' );