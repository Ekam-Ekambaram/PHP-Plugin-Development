<?php
/**
 * Plugin Name: Contact Form
 * Description: Websites Messages.
 * Author: Mr. Ekambaram
 * Author URI: https://localhost/wordpress/?page_id=1571
 * Version: 10.0.0
 * Text Domain: simple-form
 */

if (!defined('ABSPATH')) {
    echo "What are you trying to do?";
    exit;
}

class SimpleForm
{
    public function __construct()
    {
        // Create custom post type
        add_action('init', array($this, 'create_custom_post_type'));
        // Add Assets(JS, CSS, etc)
        add_action('wp_enqueue_scripts', array($this, 'load_assets'));
        // Add shortcode
        add_shortcode('contact-form', array($this, 'load_shortcode'));
        // Load Javascript
        add_action('wp_footer', array($this, 'load_scripts'));
        // Register REST API
        add_action('rest_api_init', array($this, 'register_rest_api'));
        // Add Admin Menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        // Add bulk delete AJAX handler
        add_action('wp_ajax_bulk_delete_entries', array($this, 'bulk_delete_entries'));
        // Add update submission AJAX handler
        add_action('wp_ajax_update_submission', array($this, 'update_submission'));
        // Database setup on activation
        register_activation_hook(__FILE__, [$this, 'install']);
    }

    public function create_custom_post_type()
    {
        $args = array(
            'public' => true,
            'has_archive' => true,
            'supports' => array('title'),
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'capability_type' => 'post',
            'labels' => array(
                'name' => 'Contact Form',
                'singular_name' => 'Contact Form Entry'
            ),
            'menu_icon' => 'dashicons-media-text',
        );

        register_post_type('simple_form', $args);
    }

    public function load_assets() {
        wp_enqueue_style(
            'simple-form',
            plugin_dir_url(__FILE__) . 'css/simple-form.css',
            array(),
            '1.0.0',
            'all'
        );
    
        wp_enqueue_script(
            'simple-form',
            plugin_dir_url(__FILE__) . 'js/simple-form.js',
            array('jquery'),  // Ensure jQuery is listed as a dependency
            '1.0.0',
            true
        );
    }
    

    public function load_shortcode()
    {
        ob_start(); ?>
        <div class="bg-container">
            <div class="login">
                <h1 class="heading">Sign Up</h1>
                <p class="paragraph">Please fill the below form</p>
                <form method="post" class="simple-contact-form__form">
                    <input type="text" name="name" placeholder="Name" class="input-control"/>
                    <input type="text" name="email" placeholder="Email" class="input-control"/>
                    <input type="text" name="phone" placeholder="Phone" class="input-control"/>
                    <textarea type="text" name="message" placeholder="Message" class="input-control"></textarea>
                    <button type="submit" class="btn btn-primary btn-block btn-large">Let me in.</button>
                    <span class="loading-spinner" style="display:none; margin-left: 10px;"></span>
                    </form>
                <div class="form-message input-control" style="display:none;"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function load_scripts() {
        ?>
        <script>
            var nonce = '<?php echo wp_create_nonce('wp_rest'); ?>';
            (function($){
                $('.simple-contact-form__form').submit(function(event) {
                    event.preventDefault();
                    var form = $(this);
                    var messageDiv = $('.form-message');
                    var formData = form.serializeArray();
                    var valid = true;
                    var spinner = form.find('.loading-spinner');
    
                    // Show loading spinner
                    spinner.show();
    
                    formData.forEach(function(field) {
                        if (!field.value) {
                            valid = false;
                            messageDiv.text('All fields are required.').css({
                                'color': 'white',
                                'border-color': 'red', 
                                'border-width': '2px', 
                                'border-style': 'solid', 
                                'border-radius': '5px'
                            }).show();
                            // Hide loading spinner
                            spinner.hide();
                        }
                    });
    
                    if (valid) {
                        $.ajax({
                            method: 'post',
                            url: '<?php echo get_rest_url(null, 'simple-form/v1/send-email'); ?>',
                            headers: { 'X-WP-Nonce': nonce },
                            data: form.serialize(),
                            success: function(response) {
                                var name = $('[name="name"]').val(); 
                                messageDiv.text('Hello, ' + name + ', Sign Up Successfully').css({
                                    'color': 'white',
                                    'border-color': 'Green', 
                                    'border-width': '2px', 
                                    'border-style': 'solid', 
                                    'border-radius': '5px'
                                }).show();
                                form[0].reset(); 
                                // Hide loading spinner
                                spinner.hide();
                            },
                            error: function() {
                                messageDiv.text('There was an error submitting the form. Please try again.').css('color', 'red').show();
                                // Hide loading spinner
                                spinner.hide();
                            }
                        });
                    }
                });
            })(jQuery);
        </script>
        <?php
    }
    

    public function register_rest_api()
    {
        register_rest_route('simple-form/v1', 'send-email', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_contact_form')
        ));
    }

    public function handle_contact_form($data)
    {
        $headers = $data->get_headers();
        $params = $data->get_params();
        $nonce = $headers['x_wp_nonce'][0];

        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_REST_Response('Message not sent', 422);
        }

        // Validate required fields
        if (empty($params['name']) || empty($params['email']) || empty($params['phone']) || empty($params['message'])) {
            return new WP_REST_Response('All fields are required', 422);
        }

        $post_id = wp_insert_post([
            'post_type' => 'simple_form',
            'post_title' => sanitize_text_field($params['name']),
            'post_status' => 'publish'
        ]);

        if ($post_id) {
            update_post_meta($post_id, 'name', sanitize_text_field($params['name']));
            update_post_meta($post_id, 'email', sanitize_email($params['email']));
            update_post_meta($post_id, 'phone', sanitize_text_field($params['phone']));
            update_post_meta($post_id, 'message', sanitize_textarea_field($params['message']));

            return new WP_REST_Response('Message sent successfully', 200);
        }

        return new WP_REST_Response('Message not sent', 500);
    }

    public function add_admin_menu()
    {
        add_menu_page(
            'Contact Form Entries', // Page title
            'Submissions', // Menu title
            'manage_options', // Capability
            'contact-form-entries', // Menu slug
            array($this, 'admin_page_display'), // Callback function
            'dashicons-media-text', // Icon
            6 // Position
        );
    }

    public function admin_page_display()
    {
        ?>
        <div class="wrap">
            <h1>Submissions</h1>
            <form id="bulk-delete-form" method="post">
                <table class="wp-list-table widefat fixed striped table-view-list posts">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-cb check-column">
                                <input type="checkbox" id="select-all">
                            </th>
                            <th scope="col" id="title" class="manage-column column-title column-primary">Name</th>
                            <th scope="col" id="email" class="manage-column column-email">Email</th>
                            <th scope="col" id="phone" class="manage-column column-phone">Phone</th>
                            <th scope="col" id="message" class="manage-column column-message">Message</th>
                            <th scope="col" id="actions" class="manage-column column-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $args = array(
                            'post_type' => 'simple_form',
                            'post_status' => 'publish',
                            'posts_per_page' => -1,
                        );

                        $entries = new WP_Query($args);

                        if ($entries->have_posts()) {
                            while ($entries->have_posts()) {
                                $entries->the_post();
                                $meta = get_post_meta(get_the_ID());
                                ?>
                                <tr data-id="<?php echo get_the_ID(); ?>">
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="entry_ids[]" value="<?php echo get_the_ID(); ?>">
                                    </th>
                                    <td class="title column-title has-row-actions column-primary editable" data-name="name" data-original="<?php echo get_the_title(); ?>"><?php echo get_the_title(); ?></td>
                                    <td class="email column-email editable" data-name="email" data-original="<?php echo esc_html($meta['email'][0]); ?>"><?php echo esc_html($meta['email'][0]); ?></td>
                                    <td class="phone column-phone editable" data-name="phone" data-original="<?php echo esc_html($meta['phone'][0]); ?>"><?php echo esc_html($meta['phone'][0]); ?></td>
                                    <td class="message column-message editable" data-name="message" data-original="<?php echo esc_html($meta['message'][0]); ?>"><?php echo esc_html($meta['message'][0]); ?></td>
                                    <td class="actions column-actions">
                                        <a href="#" class="edit-entry" data-id="<?php echo get_the_ID(); ?>">Edit</a>
                                        <a href="<?php echo get_delete_post_link(get_the_ID()); ?>">Delete</a>
                                    </td>
                                </tr>
                                <?php
                            }
                        } else {
                            ?>
                            <tr>
                                <td colspan="6">No entries found</td>
                            </tr>
                            <?php
                        }
                        wp_reset_postdata();
                        ?>
                    </tbody>
                </table>
                <div>
                    <button type="button" id="bulk-delete-button" class="button action">Delete Selected</button>
                </div>
            </form>

            <!-- Edit Form (Hidden by default) -->
            <div id="edit-form-container" style="display: none;">
                <h2>Edit Submission</h2>
                <form id="edit-form">
                    <input type="hidden" name="entry_id" id="entry_id">
                    <label for="edit_name">Name</label>
                    <input type="text" name="edit_name" id="edit_name" required>
                    <label for="edit_email">Email</label>
                    <input type="email" name="edit_email" id="edit_email" required>
                    <label for="edit_phone">Phone</label>
                    <input type="text" name="edit_phone" id="edit_phone" required>
                    <label for="edit_message">Message</label>
                    <textarea name="edit_message" id="edit_message" required></textarea>
                    <button type="submit" class="button button-primary">Save Changes</button>
                </form>
            </div>
        </div>
        <script>
            jQuery(document).ready(function($) {
                // Show the edit form with the current entry data
                $('.edit-entry').on('click', function(e) {
                    e.preventDefault();
                    var row = $(this).closest('tr');
                    var entryId = $(this).data('id');
                    var name = row.find('.title').text();
                    var email = row.find('.email').text();
                    var phone = row.find('.phone').text();
                    var message = row.find('.message').text();

                    $('#entry_id').val(entryId);
                    $('#edit_name').val(name);
                    $('#edit_email').val(email);
                    $('#edit_phone').val(phone);
                    $('#edit_message').val(message);

                    $('#edit-form-container').show();
                    $('html, body').animate({
                        scrollTop: $("#edit-form-container").offset().top
                    }, 1000);
                });

                // Handle edit form submission
                $('#edit-form').on('submit', function(e) {
                    e.preventDefault();
                    var formData = $(this).serialize();
                    $.ajax({
                        method: 'POST',
                        url: ajaxurl,
                        data: {
                            action: 'update_submission',
                            data: formData,
                            nonce: '<?php echo wp_create_nonce('update_submission_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Submission updated successfully.');
                                location.reload();
                            } else {
                                alert('Error updating submission: ' + response.data);
                            }
                        }
                    });
                });

                // Bulk delete
                $('#bulk-delete-button').on('click', function(e) {
                    e.preventDefault();
                    var ids = [];
                    $('input[name="entry_ids[]"]:checked').each(function() {
                        ids.push($(this).val());
                    });

                    if (ids.length > 0 && confirm('Are you sure you want to delete selected entries?')) {
                        $.ajax({
                            method: 'POST',
                            url: ajaxurl,
                            data: {
                                action: 'bulk_delete_entries',
                                entry_ids: ids,
                                nonce: '<?php echo wp_create_nonce('bulk_delete_entries_nonce'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert('Entries deleted successfully.');
                                    location.reload();
                                } else {
                                    alert('Error deleting entries: ' + response.data);
                                }
                            }
                        });
                    } else {
                        alert('Please select at least one entry to delete.');
                    }
                });

                // Select all checkboxes
                $('#select-all').on('click', function() {
                    $('input[name="entry_ids[]"]').prop('checked', this.checked);
                });
            });
        </script>
        <?php
    }

    public function bulk_delete_entries()
    {
        check_ajax_referer('bulk_delete_entries_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized user');
        }

        $entry_ids = isset($_POST['entry_ids']) ? $_POST['entry_ids'] : array();

        if (empty($entry_ids)) {
            wp_send_json_error('No entries selected for deletion');
        }

        foreach ($entry_ids as $entry_id) {
            wp_delete_post($entry_id, true);
        }

        wp_send_json_success('Entries deleted successfully');
    }

    public function update_submission()
    {
        check_ajax_referer('update_submission_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized user');
        }

        parse_str($_POST['data'], $data);

        $entry_id = intval($data['entry_id']);
        $name = sanitize_text_field($data['edit_name']);
        $email = sanitize_email($data['edit_email']);
        $phone = sanitize_text_field($data['edit_phone']);
        $message = sanitize_textarea_field($data['edit_message']);

        if ($entry_id && $name && $email && $phone && $message) {
            wp_update_post(array(
                'ID' => $entry_id,
                'post_title' => $name
            ));

            update_post_meta($entry_id, 'name', $name);
            update_post_meta($entry_id, 'email', $email);
            update_post_meta($entry_id, 'phone', $phone);
            update_post_meta($entry_id, 'message', $message);

            wp_send_json_success('Submission updated successfully.');
        } else {
            wp_send_json_error('Invalid form data.');
        }
    }

    public function install()
    {
        $this->create_custom_post_type();
        flush_rewrite_rules();
    }
}

new SimpleForm();
