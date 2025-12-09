<?php
/**
 * Plugin Name: MPro Client Portal
 * Requires Plugins: mentorpro-platform/mentorpro-platform.php
 * Plugin URI:  /plugins/mpro-client-portal
 * Description: Provides a shortcode and functions to render responsive client portal boxes with image, title, description, link, and role-based access.
 * Version: 2.0.1
 * Author: NRM
 * Text Domain: mpro-client-portal
 * License: GPLv2 or later
 *
 * Usage:
 * 1. Upload the 'mpro-client-portal' folder into your WordPress site's wp-content/plugins directory.
 * 2. Activate the “MPro Client Portal” plugin under Plugins in the WordPress admin.
 * 3. Add the shortcode [mpro_client_portal] to any post or page where you want the boxes to appear.
 * 4. (Optional) In PHP templates, call mpro_render_custom_boxes( $boxes_array ) directly with your data.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Plugin constants
if ( ! defined( 'MPRO_CLIENT_PORTAL_VERSION' ) ) {
    define( 'MPRO_CLIENT_PORTAL_VERSION', '2.0.5' );
}
if ( ! defined( 'MPRO_CLIENT_PORTAL_URL' ) ) {
    define( 'MPRO_CLIENT_PORTAL_URL', plugin_dir_url( __FILE__ ) );
}

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'includes/client-portal-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/mpro-learndash-course-report.php';
require_once plugin_dir_path(__FILE__) . 'includes/mpro-faq.php';
require_once plugin_dir_path(__FILE__) . 'includes/mpro-manage-boxes.php';

// ===== Storage keys & capability =====
if ( ! defined( 'MPRO_CLIENT_PORTAL_OPTION' ) ) {
    define( 'MPRO_CLIENT_PORTAL_OPTION', 'mpro_client_portal_boxes' );
}

/**
 * Capability used to manage boxes. Default 'manage_options'.
 * Filter with 'mpro_client_portal_manage_capability' if you want e.g. 'edit_pages' or a custom cap.
 */
function mpro_client_portal_manage_capability() {
    return apply_filters( 'mpro_client_portal_manage_capability', 'manage_options' );
}

/**
 * Get all saved boxes (array). Always returns an array.
 */
function mpro_get_saved_boxes() {
    $boxes = get_option( MPRO_CLIENT_PORTAL_OPTION, [] );
    return is_array( $boxes ) ? $boxes : [];
}

/**
 * Save boxes (array).
 */
function mpro_save_boxes( $boxes ) {
    if ( ! is_array( $boxes ) ) { $boxes = []; }
    update_option( MPRO_CLIENT_PORTAL_OPTION, array_values( $boxes ) );
}

/**
 * One-time migration helper: if no saved boxes yet, seed from your current hardcoded list in the shortcode.
 * Call this on 'init' so first admin visit seeds data once.
 */
add_action( 'init', function () {
    if ( get_option( MPRO_CLIENT_PORTAL_OPTION, null ) === null ) {
        // Don't seed here; we'll seed on first shortcode render if needed (keeps plugin activation clean).
        update_option( MPRO_CLIENT_PORTAL_OPTION, [] );
    }
});

/**
 * Enqueue manager script only where needed.
 */
function mpro_enqueue_manager_assets() {
    // Only load when the manager shortcode is present on the page
    if ( is_singular() && has_shortcode( get_post()->post_content, 'mpro_client_portal_manager' ) ) {
        wp_enqueue_script(
            'mpro-portal-manager',
            MPRO_CLIENT_PORTAL_URL . 'assets/js/mpro-portal-manager.js',
            [ 'wp-api-fetch' ],
            MPRO_CLIENT_PORTAL_VERSION,
            true
        );

        wp_localize_script( 'mpro-portal-manager', 'MProPortalManager', [
            'restBase'  => esc_url_raw( rest_url( 'mpro-client-portal/v1' ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'canManage' => current_user_can( mpro_client_portal_manage_capability() ),
        ] );

        wp_enqueue_style(
            'mpro-portal-manager-css',
            MPRO_CLIENT_PORTAL_URL . 'assets/css/mpro-portal-manager.css',
            [],
            MPRO_CLIENT_PORTAL_VERSION
        );
    }
}
add_action( 'wp_enqueue_scripts', 'mpro_enqueue_manager_assets' );

/**
 * Front-end manager: [mpro_client_portal_manager]
 */
function mpro_client_portal_manager_shortcode() {
    if ( ! is_user_logged_in() || ! current_user_can( mpro_client_portal_manage_capability() ) ) {
        return '<p>You do not have permission to manage portal boxes.</p>';
    }

    ob_start(); ?>
    <div id="mpro-portal-manager" class="mpro-portal-manager">
        <h2>Client Portal Boxes</h2>

        <form id="mpro-portal-form" class="mpro-portal-form">
            <input type="hidden" name="id" value="">

            <label>Image URL
                <input type="url" name="image" placeholder="https://example.com/image.jpg">
            </label>

            <label>Title
                <input type="text" name="title" required>
            </label>

            <label>Description
                <textarea name="description" rows="3"></textarea>
            </label>

            <label>Link URL
                <input type="url" name="link" placeholder="/client-portal/... or https://...">
            </label>

            <fieldset>
                <legend>Visible to Roles</legend>
                <label><input type="checkbox" name="roles[]" value="administrator"> Administrator</label>
                <label><input type="checkbox" name="roles[]" value="contract"> Program Manager</label>
                <label><input type="checkbox" name="roles[]" value="group_leader"> Mentor</label>
                <label><input type="checkbox" name="roles[]" value="mentee"> Mentee</label>
            </fieldset>

            <label>Visible to Clients
                <select name="clients[]" id="mpro-clients" multiple size="6"></select>
                <small>Leave empty to show to all clients.</small>
            </label>
            
            <label>Collections (comma-separated)
              <input type="text" name="collections" placeholder="e.g. secondary, resources">
              <small>Use short, URL-friendly slugs. Example: <code>secondary</code></small>
            </label>

            <div class="row-actions">
                <button type="submit" class="button button-primary">Save Box</button>
                <button type="button" id="mpro-reset" class="button">Reset</button>
            </div>
        </form>

        <hr>

        <h3>Existing Boxes</h3>
        <div id="mpro-boxes-list" class="mpro-boxes-list" aria-live="polite"></div>
        
        <div class="row-actions" style="margin-top:.5rem;">
            <button type="button" id="mpro-save-order" class="button">Save Order</button>
        </div>

    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'mpro_client_portal_manager', 'mpro_client_portal_manager_shortcode' );


/**
 * Enqueue the Client Portal stylesheet.
 */
function mpro_client_portal_enqueue_styles() {
    // In production use your defined version constant:
    $version = MPRO_CLIENT_PORTAL_VERSION;
    $version = 2.9;

    wp_enqueue_style(
        'mpro-client-portal',
        MPRO_CLIENT_PORTAL_URL . 'assets/css/mpro-client-portal.css',
        [],           // no dependencies
        $version      // version for cache-busting
    );
}
add_action( 'wp_enqueue_scripts', 'mpro_client_portal_enqueue_styles', 20 );



/**
 * Render a set of custom boxes.
 *
 * @param array $boxes Array of boxes. Each box is an associative array:
 *    - image       : (string) URL of the image
 *    - title       : (string) Box title
 *    - description : (string) Box description (HTML allowed)
 *    - link        : (string) URL to wrap the title in
 *    - roles       : (array) WordPress roles allowed to see this box
 *    - collections       : comma-separated list of sub-page content
 */
function mpro_render_custom_boxes( $boxes = [] ) {
 if ( empty($boxes) || ! is_array($boxes) ) {
     $boxes = mpro_get_saved_boxes();
 }
 if ( empty($boxes) ) return;
 
    $boxes = mpro_sort_boxes_by_order_then_title( $boxes );
 
     $ctx = mp_get_user_context();
     if ( ! is_array( $ctx ) ) {
         // fallback to prevent fatal errors
         $ctx = [
             'user_id'      => null,
             'roles'        => [],
             'primary_role' => null,
             'client_id'    => false,
         ];
     }
 
     $user_roles  = $ctx['roles'];
     $client_slug = $ctx['client_id'];
 
     echo '<div class="custom-boxes-container">';
 
     foreach ( $boxes as $box ) {
         // 1) ROLE CHECK 
         if ( ! empty( $box['roles'] ) && ! array_intersect( $user_roles, $box['roles'] ) ) {
             continue;
         }
 
         // 2) CLIENT CHECK
         if ( ! empty( $box['clients'] ) && ! in_array( $client_slug, $box['clients'], true ) ) {
             continue;
         }
 
         $img_url     = esc_url( $box['image'] ?? '' );
         $title       = esc_html( $box['title'] ?? '' );
         $description = wp_kses_post( $box['description'] ?? '' );
         $link        = esc_url( $box['link'] ?? '' );
 
         echo '<div class="custom-box">';
         if ( $img_url ) {
             echo '<a href="' . $link . '">';
             echo '<img class="custom-box__image" src="' . $img_url . '" alt="' . $title . '">';
             echo '</a>';
         }
         echo '<div class="custom-box__content">';
         if ( $link ) {
             echo '<h3 class="custom-box__title"><a href="' . $link . '">' . $title . '</a></h3>';
         } else {
             echo '<h3 class="custom-box__title">' . $title . '</h3>';
         }
         echo '<p class="custom-box__description">' . $description . '</p>';
         echo '</div></div>';
     }
 
     echo '</div>';
 }


// Shortcode handler

function mpro_client_portal_shortcode( $atts ) { $atts = shortcode_atts( [
    'include' => '', // e.g. "secondary,resources"
    'exclude' => '', // e.g. "secondary"
], $atts, 'mpro_client_portal' );   
 
    // user roles are  contract (pm), group_leader (mentor) or mentee
    /*
    $boxes = array(
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/10/kittitas.png',
                'title'       => 'THRIVE Youth Mentoring Resources',
                'description' => 'Access additional THRIVE Youth Mentoring resources',
                'link'        => 'https://template.mentorpro.com/client-portal/thrive-youth-mentoring-resources/',
                'roles'       => array('administrator','group_leader','contract'),
                'clients'       => array('kittitas' , 'mentorpro'),
            ),
        array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/04/bbbs.jpeg',
                'title'       => 'BBBSMT Big Futures Resources',
                'description' => 'Access additional BBBSMT resources',
                'link'        => 'https://template.mentorpro.com/client-portal/bbbsmt-big-futures/',
                'roles'       => array(),
                'clients'       => array('mentorpro','bbbstn'),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/01/elev8.png',
                'title'       => 'SEL for Extended Learning OST',
                'description' => 'Access Elev8 SEL for Extended Learning OST resources',
                'link'        => 'https://template.mentorpro.com/client-portal/sel-for-extended-learning-ost/',
                'roles'       => array(),
                'clients'       => array('elev8' , 'mentorpro'),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/01/sdsu.jpg',
                'title'       => 'SDSU Alumni Mentor Program Resources',
                'description' => 'Access SDSU Alumni Mentor Program resources, activities, and trackers',
                'link'        => 'https://template.mentorpro.com/client-portal/sdsu-alumni-mentor-program-resources/',
                'roles'       => array(),
                'clients'       => array('sdsu' , 'mentorpro'),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/09/stepup.jpeg',
                'title'       => 'Step Up Course Editor',
                'description' => 'Provides access to course editor for Step Up Program managers',
                'link'        => 'https://template.mentorpro.com/wp-admin/',
                'roles'       => array('administrator','contract'),
                'clients'       => array('stepup' , 'mentorpro'),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/06/abcn_logo.jpeg',
                'title'       => 'ABCN Scholarship Info Hub',
                'description' => 'Important information about your scholarship',
                'link'        => 'https://template.mentorpro.com/client-portal/abcn-career-resources',
                'roles'       => array(),
                'clients'       => array('mentorpro','abcn'),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/04/leap.png',
                'title'       => 'Mentor/Mentee Data Report',
                'description' => 'Matching forms submission management for Lynn',
                'link'        => 'https://template.mentorpro.com/leap4ed-chp-data-report/',
                'roles'       => array('administrator','contract'),
                'clients'       => array('leap4ed','mentorpro'),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/My-Courses.png',
                'title'       => 'MentorAI PM Guide',
                'description' => 'PM overview of MentorAI and guidance for training and supervising mentors',
                'link'        => 'https://template.mentorpro.com/client-portal/mentorai-program-manager-guide/',
                'roles'       => array('administrator','contract'),
                'clients'       => array('umb','umb-cla','drewai','mentorpro','demo'),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/My-Courses.png',
                'title'       => 'My Courses',
                'description' => 'View my MentorPRO Academy courses',
                'link'        => 'https://template.mentorpro.com/courses/?type=my-courses',
                'roles'       => array('administrator','group_leader', 'mentee'),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/Mentee-Notes.png',
                'title'       => 'Mastering MentorPRO',
                'description' => 'Learn all the ins and outs of MentorPRO and guide your program to mentoring success!',
                'link'        => 'https://template.mentorpro.com/courses/mastering-mentorpro',
                'roles'       => array('administrator','contract'),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/Help-Center.png',
                'title'       => 'PM Help Center',
                'description' => 'Find quick solutions to common problems and learn about key product features',
                'link'        => 'https://template.mentorpro.com/client-portal/program-manager-help-center/',
                'roles'       => array('administrator','contract'),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/09/stepup.jpeg',
                'title'       => 'Step Up Career Coaching Resources',
                'description' => 'Resources and documents for the Step Up Steps to Success: Career Coaching Program',
                'link'        => 'https://template.mentorpro.com/client-portal/step-up-career-coaching-resources/',
                'roles'       => array(),
                'clients'       => array('stepup','mentorpro'),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/Help-Center.png',
                'title'       => 'Mentor Help Center',
                'description' => 'Find quick solutions to common problems and learn about key product features',
                'link'        => 'https://template.mentorpro.com/client-portal/mentor-help-center/',
                'roles'       => array('administrator','group_leader'),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/Help-Center.png',
                'title'       => 'Mentee Help Center',
                'description' => 'Find quick solutions to common problems and learn about key product features',
                'link'        => 'https://template.mentorpro.com/client-portal/mentee-help-center',
                'roles'       => array('administrator','mentee'),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/Resources.png',
                'title'       => 'Resources',
                'description' => 'Trainings, documents, videos, and more for you, your Mentors, and Mentees',
                'link'        => 'https://template.mentorpro.com/resources-for-pms/',
                'roles'       => array('administrator','contract'),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/Resources.png',
                'title'       => 'Resources',
                'description' => 'Documents, videos, and posters for Mentees',
                'link'        => 'https://template.mentorpro.com/client-portal/resources-for-mentees/',
                'roles'       => array('administrator','mentee'),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/Resources.png',
                'title'       => 'Resources',
                'description' => 'Documents, videos, and posters for Mentors',
                'link'        => 'https://template.mentorpro.com/client-portal/resources-for-mentors/',
                'roles'       => array('administrator','group_leader'),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/My-Success.png',
                'title'       => 'My Success',
                'description' => 'Access previous Success Meeting slides with key insights about your program',
                'link'        => 'https://template.mentorpro.com/my-client/',
                'roles'       => array('administrator','contract'),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/My-Courses.png',
                'title'       => 'Courses Assigned to Your Program',
                'description' => 'View current MentorPRO Academy Courses available to your program',
                'link'        => 'https://template.mentorpro.com/pm-course-report/',
                'roles'       => array('administrator','contract', 'group_leader'),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/Course-Catalogue.png',
                'title'       => 'Course Catalog',
                'description' => 'Browse all published MentorPRO Academy courses available for purchase',
                'link'        => 'https://template.mentorpro.com/courses/',
                'roles'       => array('administrator','contract'),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/Academy-Dashboard.png',
                'title'       => 'Academy Dashboard',
                'description' => 'View data and course progress reports for your Mentees and Mentors on the Academy',
                'link'        => 'https://template.mentorpro.com/reporting-dashboard/',
                'roles'       => array('administrator','contract', 'group_leader'),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/Document-Manager.png',
                'title'       => 'Document Manager [BETA]',
                'description' => 'Upload, share, view, and save documents with your Mentees',
                'link'        => 'https://template.mentorpro.com/mentorpro-document-upload/',
                'roles'       => array('administrator','contract', 'group_leader','mentee'),
                //'roles'       => array(),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/Mentee-Notes.png',
                'title'       => 'Mentee Notes [BETA]',
                'description' => 'Create and save notes on your program’s Mentees, accessible by all Program Admins and Mentors',
                'link'        => 'https://template.mentorpro.com/mentee-notes/',
                'roles'       => array('administrator','contract', 'group_leader'),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/NG-resources-icon.png',
                'title'       => 'National Guard Resources',
                'description' => 'Checklists, worksheets, handouts, and other helpful resources for ChalleNGe Academies',
                'link'        => 'https://template.mentorpro.com/client-portal/national-guard-resources/',
                'roles'       => array('administrator','contract'),
                'clients'       => array('mentorpro' ,'demo','ngyc-ap' , 'ngyc-ak' , 'ngyc-bg' , 'ngyc-ca' , 'id-youth-challenge' , 'ngyc-mi' , 'ngyc-nm-job' ,
    'ngyc-nm' , 'tbird' , 'ngyc-or' , 'ngyc-pa' , 'ngyc-va' , 'ngyc-wa', 'ngyc-wvjob' , 'ngyc-wvnorth' , 'ngyc-wvsouth'),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/08/G2-300x300-1.webp',
                'title'       => 'Review MentorPRO!',
                'description' => 'Please take a moment to review MentorPRO',
                'link'        => 'https://www.g2.com/contributor/july-2025-free-attempt-085c1289-6189-4f24-8268-634979c04342',
                'roles'       => array('administrator','contract'),
                'clients'       => array(),
            ),
            array(
                'image'       => 'https://template.mentorpro.com/wp-content/uploads/2025/07/Mentee-Notes.png',
                'title'       => 'Client ID Report',
                'description' => 'Admin only resource - list all client IDs',
                'link'        => 'https://template.mentorpro.com/clients-report',
                'roles'       => array('administrator'),
                'clients'       => array(),
            ),
        );

    //$include = array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', $atts['include'] ) ) ) );
    //$exclude = array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', $atts['exclude'] ) ) ) );

// If no saved boxes yet, seed once. 
$seed = $boxes; // ← use your hard-coded boxes as the seed
*/
$seed = [];

        $saved = mpro_get_saved_boxes();
//if (empty( $seed )) error_log('seed is empty');
//if (empty( $saved )) error_log('saved is empty');
        //if ( empty( $saved ) && ! empty( $seed ) ) {
            // Ensure each has an id
          //  foreach ( $seed as &$b ) {
            //    if ( empty( $b['id'] ) ) { $b['id'] = wp_generate_uuid4(); }
            //}
            //mpro_save_boxes( $seed );
            //$saved = $seed;
        //}
    
        $boxes = $saved;
        
        // Filter by collections before render
        if ($atts['include'] !== '' || $atts['exclude'] !== '') {
            $boxes = mpro_filter_boxes_by_collections($boxes, $atts['include'], $atts['exclude']);
        }
        
        ob_start();
        mpro_render_custom_boxes( $boxes );
        return ob_get_clean();
    }
add_shortcode( 'mpro_client_portal', 'mpro_client_portal_shortcode' );

function mpro_breadcrumbs_shortcode() {
    // Only show breadcrumbs if user is logged in
    if ( ! is_user_logged_in() ) {
        return '';
    }
    
    // Always start from the *queried* object, not global $post
    $queried_id  = get_queried_object_id();
    $breadcrumb  = '<nav class="breadcrumb" style="margin-bottom: 1em;">';

    if ( is_category() || is_single() ) {
        $categories = get_the_category();
        if ( $categories ) {
            $breadcrumb .= '<a href="' 
                . get_category_link( $categories[0]->term_id ) . '">'
                . esc_html( $categories[0]->name ) . '</a>';
        }
        if ( is_single() ) {
            $breadcrumb .= ' &raquo; ' . get_the_title();
        }

    } elseif ( is_page() ) {
        $ancestors = get_post_ancestors( $queried_id );
        if ( $ancestors ) {
            $ancestors = array_reverse( $ancestors );
            foreach ( $ancestors as $ancestor_id ) {
                $breadcrumb .= '<a href="' 
                    . get_permalink( $ancestor_id ) . '">'
                    . esc_html( get_the_title( $ancestor_id ) ) . '</a> &raquo; ';
            }
        }
        $breadcrumb .= esc_html( get_the_title( $queried_id ) );

    } elseif ( is_search() ) {
        $breadcrumb .= 'Search results for: ' . get_search_query();

    } elseif ( is_404() ) {
        $breadcrumb .= '404 Not Found';
    }

    $breadcrumb .= '</nav>';
    return $breadcrumb;
}
add_shortcode( 'mpro_breadcrumbs', 'mpro_breadcrumbs_shortcode' );


/**/
/**/
/**/
/**/
/**/
/** ACF TEMPLATE FOR CLIENT HELPER **/

/**
 * Display all “success_spreadsheet” files for a given Client post.
 * First file is embedded; others are output as links.
 *
 * @param int $client_id The post ID of the Client.
 */
function render_client_spreadsheets( $client_id ) {
    // Bail if ACF isn’t active or no rows

    if ( ! function_exists('have_rows') || ! have_rows( 'success_spreadsheet', $client_id ) ) {
        
        ?>
        
        <h3>Your Success Meetings summaries will be here shortly.</h3> 
        <p>MentorPRO offers Customer Success Meetings to help your program
        achieve its goals. During these meetings, MentorPRO staff members will review engagement data for mentors and mentees in the program, the number of profiles set up, check-in results, messaging review, etc. </p>
        <p>
        We recommend:
        <ul>
        <li>Meet with the MentorPRO team daily for 10-15 min after going live to ensure that everyone has access and the Program Staff feels confident to use the app.</li>
            <li>Meet bi-weekly or monthly to review results and create strategies to achieve your program’s goals.</li>
        </ul>
        </p>
        <p>If you have any questions, please reach out to <a href="mailto:support@mentorpro.com">our support team.</a>
        
        <?php
        return;
    }

    echo '<div class="client-spreadsheets">';

// Collect all rows first
    $rows = [];
    if ( have_rows( 'success_spreadsheet', $client_id ) ) {
        while ( have_rows( 'success_spreadsheet', $client_id ) ) {
            the_row();
            $file_id = get_sub_field( 'success_xls' );
            if ( $file_id ) {
                $rows[] = $file_id;
            }
        }
    }
    
    // Reverse to make newest first (last row is treated as newest)
    $rows = array_reverse( $rows );
    
    // Output the newest embedded, others as links
    foreach ( $rows as $index => $file_id ) {
        $file_url = wp_get_attachment_url( $file_id );
        if ( ! $file_url ) {
            continue;
        }
    
        if ( $index === 0 ) {
            // Newest file: embed it
            echo '<div class="spreadsheet-embed">';
            echo do_shortcode( sprintf(
                '[pdf-embedder url="%s"]',
                esc_url( $file_url )
            ) );
            echo '</div>';
        } else {
            // Older files: just link them
            $filename = basename( parse_url( $file_url, PHP_URL_PATH ) );
            echo sprintf(
                '<div class="spreadsheet-link"><a href="%s" target="_blank" rel="noopener">%s</a></div>',
                esc_url( $file_url ),
                esc_html( $filename )
            );
        }
    }
    
    echo '</div>';
}
?>