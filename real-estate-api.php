<?php
/**
 * Real Estate API
 *
 * @package     Real Estate API
 * @author      Yury Khomich
 * @copyright   2021 Yurso Corp
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: Real Estate API
 * Plugin URI:  https://mehdinazari.com/how-to-create-hello-world-plugin-for-wordpress
 * Description: This plugin provide an API for real estate
 * Version:     1.0.0
 * Author:      Yurso
 * Author URI:  http://khos.ru
 * Text Domain: real-estate_api
 * License:     GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Realia_Widgets
 *
 * @class Realia_Widgets
 * @package Realia/Classes/Widgets
 * @author Pragmatic Mates
 */
class real_estate_api {
    /**
     * Initialize widgets
     *
     * @access public
     * @return void
     */
    public static function init() {

        add_action( 'rest_api_init', function () {
            register_rest_route( 'real-estate/v1', '/property', array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'real_estate_api::create_new_item'
            ));
            register_rest_route( 'real-estate/v1', '/archivate', array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'real_estate_api::archivate_item'
            ));
            register_rest_route( 'real-estate/v1', '/agents', array(
                'methods' => 'GET',
                'callback' => 'real_estate_api::get_agents'
            ));
            register_rest_route( 'real-estate/v1', '/import_agents', array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'real_estate_api::import_agents'
            ));
        });

    }

    public static function create_new_item( $data ) {

        set_time_limit(0);

        $result = array(
            'success' => false,
            'message' => '',
            'post_id' => 0
        );

        $update = false;

        if ($data['hash'] != '') {
            $result['message'] = 'Auth error';
            return $result;
        }

        $post_args = array(
            'post_type'     => 'property',
            'post_status'   => 'publish',
            'post_title'    => !empty( $data['post_title'] ) ? $data['post_title'] : '',
            'post_content'  => !empty( $data['post_content'] ) ? $data['post_content'] : ''
        );

        // update if isset post_id and post exists
        if (!empty($data['post_id'])) {
            $post_before = get_post($data['post_id']);
            if (!is_null($post_before)) {
                $post_args['ID'] = $data['post_id'];
                $update = true;
            }
        }

        if (!empty($data['author_id'])) {
            $post_args['post_author'] = $data['author_id'];
        }

        $post_id = wp_insert_post($post_args);

        // Property Type
        self::import_terms( $data['property_types'], $post_id, 'property-type' );
        // Property status
        self::import_terms( $data['property_status'], $post_id, 'property-status' );
        // Сity
        self::import_terms( $data['location'], $post_id, 'property-city' );
        // Location
        self::import_field( $post_id, 'REAL_HOMES_property_location',  $data['location_latitude'].','.$data['location_longitude'].',14');
        // Size postfix
        self::import_field( $post_id, 'REAL_HOMES_property_size_postfix', 'кв. м');

        // Adding fields
        $fields = array(
            'REAL_HOMES_property_price' => 'price',
            'REAL_HOMES_property_id' => 'property_id',
            'REAL_HOMES_property_size' => 'total_area',
            'REAL_HOMES_property_floor' => 'floor',
            'REAL_HOMES_property_floors' => 'floors',
            'REAL_HOMES_property_bedrooms' => 'rooms',
            'REAL_HOMES_property_bathrooms' => 'wc_type',
            'REAL_HOMES_property_loggia_type' => 'loggia_type',
            'REAL_HOMES_property_house_type' => 'house_type',
            'REAL_HOMES_property_acres' => 'acres',
            'REAL_HOMES_property_land_type' => 'land_type',
            'REAL_HOMES_property_address' => 'address'
        );

        foreach ($fields as $key => $value) {
            if (isset($data[$value])) {
                self::import_field( $post_id, $key, $data[$value]);
            }
        }

        if ($data['property_types'] == 'snt' or $data['property_types'] == 'uсhastki') {
            self::import_field( $post_id, 'REAL_HOMES_property_size', $data['acres']);
            self::import_field( $post_id, 'REAL_HOMES_property_size_postfix', 'Соток');
        }

        if ($data['featured'] == 1) {            
            self::import_field( $post_id, 'REAL_HOMES_featured', 1);
        } else {
            self::import_field( $post_id, 'REAL_HOMES_featured', 0);            
        }

        $additional_details = array(
            'Комнат' => $data['rooms'],
            'Общая площадь, кв.м' => $data['total_area'],
            'Этаж' => $data['floor'],
            'Этажность' => $data['floors'],
            'Сан. узел' => $data['wc_type'],
            'Балкон/Лоджия' => $data['loggia_type'],
            'Тип дома' => $data['house_type'],
            'Соток земли' => $data['acres'],
            'Назначение земли' => $data['land_type'],
        );

        self::import_field( $post_id, 'REAL_HOMES_additional_details', $additional_details);

        // Agents
        if (!empty($data['agent_id'])) {
            self::import_field( $post_id, 'REAL_HOMES_agents', $data['agent_id']);
            self::import_field( $post_id, 'REAL_HOMES_agent_display_option', 'agent_info');
        }

        // Delete old attachments if it is upadte
        if ($update) {
            // get post attachments
            $gallery = get_post_meta( $post_id, 'REAL_HOMES_property_images', true );
            // foreach all attachments
            foreach ( $gallery as $id => $src ) {
                // delete attachment
                wp_delete_attachment($id, true);
            }
            delete_post_meta( $post_id, 'REAL_HOMES_property_images' );
        }

        // Gallery
        if (!empty($data['images'])) {
            $images = explode(' ', $data['images'] );
            //$gallery = array();
            $thumbnail = false;
            foreach ( $images as $image ) {
                $attachment_id = self::import_attachment( $image, $post_id );
                if (!$thumbnail) {
                    set_post_thumbnail( $post_id, $attachment_id );
                    $thumbnail = true;
                }
                add_post_meta( $post_id, 'REAL_HOMES_property_images', $attachment_id );
            }
            
        }

        $result = array(
            'success' => true,
            'message' => 'New item was created',
            'post_id' => $post_id
        );

        return $result;

    }

    public static function archivate_item( $data ) {

        $result = array(
            'success' => false,
            'message' => '',
        );

        if ($data['hash'] != '') {
            $result['message'] = 'Auth error';
            return $result;
        }

        if (empty($data['post_id'])) {
            $result['message'] = 'Wrong params number';
            return $result;
        }

        $post_id = intval($data['post_id']);
        $result['post_id'] = $post_id;

        $post = get_post($post_id, ARRAY_A);

        if ($post == null) {
            $result['message'] = 'Post does not exist';            
            return $result;
        }

        if ($post['post_type'] != 'property') {
            $result['message'] = 'Wrong post type';            
            return $result; 
        }

        if (!wp_delete_post($post_id)) {
            $result['message'] = 'Problem with wp_delete_post';            
        } else {
            $result['success'] = true;
            $result['message'] = 'Post deleted';            
        }

        return $result;

    }

    public static function get_agents($data) {

        $posts = get_posts(array(
            'post_type' => 'agent',
            'post_status' => 'publish',
            'numberposts' => 9999,
            'orderby'     => 'title',
            'order' => 'ASC'
        ));
         
        if ( empty( $posts ) ) {
            return null;
        }
         
        return $posts;

    }

    public static function get_users($data) {

        $result = array();

        $users = get_users();
         
        if ( empty( $users ) ) {
            return null;
        }

        foreach ($users as $user) {
            
            $result[] = array(
                'ID' => $user->data->ID,
                'name' => $user->data->display_name 
            );

        }
         
        return $result;

    }

    public static function check_auth($data) {

        return current_user_can('edit_others_posts');

    }

    public static function import_agents( $data ) {

        $result = array(
            'success' => false,
            'message' => '',
            'import_results' => array()
        );

        if ($data['hash'] != '') {
            $result['message'] = 'Auth error';
            return $result;
        }

        if (!isset($data['agents'])) {
            $result['message'] = 'No agents data';
            return $result;
        }

        foreach ($data['agents'] as $agent) {

            $post_status = 'publish';

            if ($agent['activated'] == 0 or $agent['deleted'] == 1 or $agents['wp_export_to_agents'] == 0) {
                $post_status = 'draft';
            }

            $post_args = array(
                'post_type'     => 'agent',
                'post_status'   => $post_status,
                'post_title'    => !empty( $agent['name'] ) ? $agent['name'] : '',
                'post_content'  => ''
            );

            // update if isset post_id and post exists
            if (!empty($agent['wp_agent_id'])) {
                $post_before = get_post($agent['wp_agent_id']);
                if (!is_null($post_before)) {
                    $post_args['ID'] = $agent['wp_agent_id'];
                    $update = true;
                }
            }

            $post_id = wp_insert_post($post_args);

            $result['import_results'][] = array(
                'agent_id' => $agent['id'],
                'post_id' => $post_id
            );

        }

        return $result;


    }

    /**
     * Loads image from external source
     *
     * @access public
     * @param $filename
     * @param $post_id
     * @return int
     */
    public static function import_attachment( $filename, $post_id ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $is_external = false;

        // If the image path is starting with http we are gonna to download it
        if (substr( $filename, 0, 4 ) === 'http') {
            $is_external = true;

            $image_id = media_sideload_image( $filename, $post_id, null, 'id');
            $image = wp_get_attachment_image_src( $image_id, 'full' );
        }

        $filetype = wp_check_filetype( basename( $filename ), null );
        $wp_upload_dir = wp_upload_dir();
        $args = array(
            'guid'           => $wp_upload_dir['url'] . '/' . basename( $filename ),
            'post_mime_type' => $filetype['type'],
            'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $filename ) ),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        return $image_id;
    }

    /**
     * Saves regular fields
     *
     * @access public
     * @param $post_id
     * @param $key
     * @param $value
     * @return bool
     */
    public static function import_field( $post_id, $key, $value ) {
        if ( ! empty( $value ) ) {
            update_post_meta( $post_id, $key, $value );
            return true;
        }

        return false;
    }

    /**
     * Imports terms
     *
     * @access public
     * @param $terms
     * @param $post_id
     * @param $taxonomy
     * @return bool
     */
    public static function import_terms( $terms, $post_id, $taxonomy) {
        $parsed_terms = explode( ', ', $terms );
        $term_ids = array();

        if ( empty( $terms ) ) {
            return false;
        }

        foreach ( $parsed_terms as $term ) {
            $term = trim( $term );
            $existing_term = get_term_by( 'slug', sanitize_title( $term ), $taxonomy );

            if ( empty( $existing_term ) ) {
                $term_id = wp_insert_term( $term, $taxonomy );
            } else {
                $term_id = $existing_term->term_id;
            }

            $term_ids[] = $term_id;
        }

        wp_set_post_terms( $post_id, $term_ids, $taxonomy );
        return true;
    }

}

real_estate_api::init();

