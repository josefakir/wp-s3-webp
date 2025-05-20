<?php
/**
 * Plugin Name:     S3 Uploader WebP
 * Description:     Converts images to WebP, uploads them to an S3 bucket, and forces .webp usage in the Media Library and REST API.
 * Version:         1.5
 * Author:          Pepe Becerra
 * Text Domain:     my-s3-uploader-webp
 *
 * How it works:
 * 1.  Images are converted to WebP on upload (GD or Imagick).
 * 2.  The resulting WebP (or the original file if conversion fails) is pushed to S3.
 * 3.  Attachment URLs are rewritten so that WordPress always serves the S3 version.
 * 4.  For any non‑image attachment (PDF, DOCX, etc.) the file is uploaded to S3 unchanged.
 *
 * Environment variables expected in a `.env` file (plugin folder or WP root):
 *   AWS_ACCESS_KEY_ID
 *   AWS_SECRET_ACCESS_KEY
 *   AWS_REGION          (defaults to "us-east-1" if missing)
 *   AWS_BUCKET          (defaults to "backend-audiorama-media" if missing)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Aws\S3\S3Client;
use Dotenv\Dotenv;

class My_S3_Uploader_WebP {

    private $s3;
    private $bucket;

    public function __construct() {

        /* -------------------------------------------------------------
         * 1.  Load environment variables (.env)
         * ------------------------------------------------------------ */
        $loaded = false;

        // First look for .env inside the plugin folder
        if ( file_exists( __DIR__ . '/.env' ) ) {
            $dotenv = Dotenv::createImmutable( __DIR__ );
            $dotenv->safeLoad();
            $loaded = true;
        }
        // If not found, fall back to the WordPress root
        if ( ! $loaded && file_exists( ABSPATH . '/.env' ) ) {
            $dotenv = Dotenv::createImmutable( ABSPATH );
            $dotenv->safeLoad();
        }

        $region = getenv( 'AWS_REGION' ) ?: 'us-east-1';
        $bucket = getenv( 'AWS_BUCKET' ) ?: 'backend-audiorama-media';
        $access = getenv( 'AWS_ACCESS_KEY_ID' );
        $secret = getenv( 'AWS_SECRET_ACCESS_KEY' );

        // Abort and warn if credentials are missing
        if ( empty( $access ) || empty( $secret ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>S3 Uploader WebP:</strong> Environment variables <code>AWS_ACCESS_KEY_ID</code> and/or <code>AWS_SECRET_ACCESS_KEY</code> are missing.</p></div>';
            } );
            return;
        }

        /* -------------------------------------------------------------
         * 2.  Instantiate the S3 client
         * ------------------------------------------------------------ */
        $this->s3 = new S3Client( [
            'version'     => 'latest',
            'region'      => $region,
            'credentials' => [
                'key'    => $access,
                'secret' => $secret,
            ],
        ] );

        $this->bucket = $bucket;

        /* -------------------------------------------------------------
         * 3.  Register WordPress hooks
         * ------------------------------------------------------------ */
        add_filter( 'wp_generate_attachment_metadata', [ $this, 'process_and_upload' ], 99, 2 );
        add_filter( 'wp_get_attachment_url',          [ $this, 'rewrite_attachment_url' ], 99, 2 );
        add_filter( 'wp_prepare_attachment_for_js',   [ $this, 'force_original_in_library' ], 10, 3 );
        add_action( 'add_attachment',                 [ $this, 'upload_any_attachment' ], 20 );
    }

    /* -----------------------------------------------------------------
     * Convert the original image to WebP (GD first, fallback to Imagick)
     * ---------------------------------------------------------------- */
    private function convert_to_webp( $orig ) {
        $info = pathinfo( $orig );
        $webp = $info['dirname'] . '/' . $info['filename'] . '.webp';

        // --- GD extension ------------------------------------------------
        if ( extension_loaded( 'gd' ) && $size = getimagesize( $orig ) ) {
            [, , $type] = $size;
            switch ( $type ) {
                case IMAGETYPE_JPEG:
                    $img = imagecreatefromjpeg( $orig );
                    break;
                case IMAGETYPE_PNG:
                    $img = imagecreatefrompng( $orig );
                    imagepalettetotruecolor( $img );
                    imagealphablending( $img, true );
                    imagesavealpha( $img, true );
                    break;
                default:
                    return false;
            }
            imagewebp( $img, $webp, 80 );
            imagedestroy( $img );
            return $webp;
        }

        // --- Imagick extension ------------------------------------------
        if ( extension_loaded( 'imagick' ) ) {
            try {
                $im = new \Imagick( $orig );
                $im->setImageFormat( 'webp' );
                $im->setImageCompressionQuality( 80 );
                $im->writeImage( $webp );
                $im->clear();
                $im->destroy();
                return $webp;
            } catch ( \Exception $e ) {
                error_log( 'WebP conversion failed: ' . $e->getMessage() );
                return false;
            }
        }

        return false;
    }

    /* -----------------------------------------------------------------
     * Upload a single file to the configured S3 bucket
     * ---------------------------------------------------------------- */
    private function upload_s3_object( $relative ) {
        $up   = wp_upload_dir();
        $file = $up['basedir'] . '/' . $relative;

        if ( ! file_exists( $file ) ) return;

        try {
            $this->s3->putObject( [
                'Bucket'      => $this->bucket,
                'Key'         => $relative,
                'Body'        => fopen( $file, 'r' ),
                'ContentType' => mime_content_type( $file ),
            ] );
        } catch ( \Exception $e ) {
            error_log( 'S3 upload error: ' . $e->getMessage() );
        }

        // Remove the local copy to save disk space
        @unlink( $file );
    }

    /* -----------------------------------------------------------------
     * Filter: wp_generate_attachment_metadata (images only)
     * ---------------------------------------------------------------- */
    public function process_and_upload( $metadata, $attachment_id ) {
        $orig = get_attached_file( $attachment_id );
        $webp = $this->convert_to_webp( $orig );

        if ( $webp ) {
            // Replace the original with the WebP
            @unlink( $orig );
            $up       = wp_upload_dir();
            $relative = ltrim( str_replace( $up['basedir'], '', $webp ), '/' );
            update_post_meta( $attachment_id, '_wp_attached_file', $relative );
            $metadata['file']  = $relative;
            $metadata['sizes'] = []; // Remove generated sizes
        }

        // Upload either the WebP or the original file
        $this->upload_s3_object( $metadata['file'] );
        return $metadata;
    }

    /* -----------------------------------------------------------------
     * Filter: wp_get_attachment_url ➜ point to the S3 URL
     * ---------------------------------------------------------------- */
    public function rewrite_attachment_url( $url, $post_id ) {
        $up   = wp_upload_dir();
        $base = $up['baseurl'];
        $s3   = 'https://' . $this->bucket . '.s3.amazonaws.com';
        return str_replace( $base, $s3, $url );
    }

    /* -----------------------------------------------------------------
     * Filter: wp_prepare_attachment_for_js ➜ always show the "original"
     * (now the WebP) in the Media Library thumbnails.
     * ---------------------------------------------------------------- */
    public function force_original_in_library( $response, $attachment, $meta ) {
        $src = wp_get_attachment_url( $attachment->ID );
        foreach ( [ 'thumbnail', 'medium', 'large' ] as $size ) {
            if ( isset( $response['sizes'][ $size ] ) ) {
                $response['sizes'][ $size ]['url'] = $src;
            }
        }
        return $response;
    }

    /* -----------------------------------------------------------------
     * Action: add_attachment ➜ upload any non‑image file (PDF, DOCX, …)
     * ---------------------------------------------------------------- */
    public function upload_any_attachment( $attachment_id ) {
        $file = get_attached_file( $attachment_id );
        if ( ! file_exists( $file ) ) return;

        $up       = wp_upload_dir();
        $relative = ltrim( str_replace( $up['basedir'], '', $file ), '/' );
        $this->upload_s3_object( $relative );
    }
}

new My_S3_Uploader_WebP();
