<?php
/**
 * Plugin Name: ShortPixel Image Optimizer
 * Plugin URI: https://wordpress.org/plugins/shortpixel-image-optimiser/
 *
 * Compatibility Description: Ensures compatibility with ShortPixel Image Optimizer.
 *
 * @todo Use backup version of image on Regenerate and Sync with GCS.
 * @todo Test for CDN mode also, currently optimized for Stateless Mode.
 */

namespace wpCloud\StatelessMedia {

    if(!class_exists('wpCloud\StatelessMedia\ShortPixel')) {
        
        class ShortPixel extends ICompatibility {

            protected $id = 'shortpixel';
            protected $title = 'ShortPixel Image Optimizer';
            protected $constant = 'WP_STATELESS_COMPATIBILITY_SHORTPIXEL';
            protected $description = 'Ensures compatibility with ShortPixel Image Optimizer.';
            protected $plugin_file = 'shortpixel-image-optimiser/wp-shortpixel.php';

            public function module_init($sm){
                add_action( 'shortpixel_image_optimised', array($this, 'shortpixel_image_optimised') );
                add_filter( 'shortpixel_image_exists', array($this, 'shortpixel_image_exists'), 10, 4 );
                add_filter( 'shortpixel_skip_backup', array($this, 'shortpixel_skip_backup'), 10, 3 );
                add_filter( 'wp_update_attachment_metadata', array( $this, 'wp_update_attachment_metadata' ), 10, 2 );
                add_filter( 'shortpixel_skip_delete_backups_and_webps', array( $this, 'shortpixel_skip_delete_backups_and_webps' ), 10, 2 );
                add_filter( 'shortpixel_backup_folder', array( $this, 'getBackupFolderAny' ), 10, 3 );

                // add_filter( 'get_attached_file', array($this, 'fix_missing_file'), 10, 2 );
                // add_action( 'shortpixel_start_image_optimisation', array($this, 'fix_missing_file') );
                add_action( 'shortpixel_before_restore_image', array($this, 'shortpixel_before_restore_image') );
                add_action( 'shortpixel_after_restore_image', array($this, 'handleRestoreBackup') );
                add_action( 'admin_enqueue_scripts', array( $this, 'shortPixelJS') );
                // Sync from sync tab
                add_action( 'sm:synced::image', array( $this, 'sync_backup_file'), 10, 2 );
            }

            public function shortPixelJS(){
                $upload_dir = wp_upload_dir();
                $jsSuffix = '.min.js';

                if (defined('SHORTPIXEL_DEBUG') && SHORTPIXEL_DEBUG === true) {
                    $jsSuffix = '.js'; //use unminified versions for easier debugging
                }
                $dep = 'short-pixel' . $jsSuffix;
                wp_enqueue_script('stateless-short-pixel', ud_get_stateless_media()->path( 'lib/classes/compatibility/js/shortpixel.js', 'url'), array($dep), '', true);
                
                $image_host = ud_get_stateless_media()->get_gs_host();
                $bucketLink = apply_filters('wp_stateless_bucket_link', $image_host);
                
                wp_localize_script( 'stateless-short-pixel', '_stateless_short_pixel', array(
                    'baseurl' => $upload_dir[ 'baseurl' ],
                    'bucketLink' => $bucketLink,
                ));

            }

            public function getBackupFolderAny( $ret, $file, $thumbs ) {
                if($ret == false){
                    $fullSubDir = $this->returnSubDir($file);
                    $ret = SHORTPIXEL_BACKUP_FOLDER . '/' . $fullSubDir;
                    
                }
                return $ret;
            }


            public function shortpixel_image_exists( $return, $path, $id = null ) {

                if($return == false && !empty($id)){
                    $metadata = wp_get_attachment_metadata($id);
                    $basename = basename($path);
                    if(!empty($metadata['gs_name'])){
                        $gs_basename = basename($metadata['gs_name']);
                        if($gs_basename == $basename){
                            return true;
                        }

                        if(is_array($metadata['sizes'])){
                            foreach ($metadata['sizes'] as $key => &$data) {
                                if(empty($metadata['gs_name'])) continue;
                                $gs_basename = basename($data['gs_name']);
                                if($gs_basename == $basename){
                                    return true;
                                }
                            }
                        }
                    }
                }
                else if($return == false && empty($id)){
                    $wp_uploads_dir = wp_get_upload_dir();
                    $gs_name = str_replace(trailingslashit($wp_uploads_dir['basedir']), '', $path);
                    $gs_name = str_replace(trailingslashit($wp_uploads_dir['baseurl']), '', $gs_name);
                    $gs_name = apply_filters( 'wp_stateless_file_name', $gs_name);
                    if ( $media = ud_get_stateless_media()->get_client()->media_exists( $gs_name ) ) {
                        return true;
                    }
                    
                }

                return $return;
            }

            public function shortpixel_skip_delete_backups_and_webps($return, $paths){
                if(empty($paths) || !is_array($paths)) return $return;

                $sp__uploads = wp_upload_dir();
                $fullSubDir = $this->returnSubDir($paths[0]);
                $backup_path = SHORTPIXEL_BACKUP_FOLDER . '/' . $fullSubDir;

                foreach ($paths as $key => $path) {
                    $name = str_replace($sp__uploads['basedir'], '', $path);
                    $name = apply_filters( 'wp_stateless_file_name',  SHORTPIXEL_BACKUP . '/' . $fullSubDir . basename($path));
                    do_action( 'sm:sync::deleteFile', $name);

                }
                
                return true;
            }

            public function shortpixel_skip_backup( $return, $mainPath, $PATHs ){
                return true;
            }
            /**
             * 
             */
            public function wp_update_attachment_metadata( $metadata, $attachment_id ){
                $backup_images = \WPShortPixelSettings::getOpt('wp-short-backup_images');
                if($backup_images){
                    $this->sync_backup_file($attachment_id, $metadata, true);
                }
                return $metadata;
            }

            /**
             * Try to restore images before compression
             *
             * @param $file
             * @param $attachment_id
             * @return mixed
             */
            public function fix_missing_file( $attachment_id ) {

                /**
                 * If mode is stateless then we change it to cdn in order images not being deleted before optimization
                 * Remember that we changed mode via global var
                 */
                if ( ud_get_stateless_media()->get( 'sm.mode' ) == 'stateless' ) {
                    ud_get_stateless_media()->set( 'sm.mode', 'cdn' );
                    global $wp_stateless_shortpixel_mode;
                    $wp_stateless_shortpixel_mode = 'stateless';
                }

                $upload_dir = wp_upload_dir();
                $file = get_attached_file($attachment_id);
                $meta_data = wp_get_attachment_metadata( $attachment_id );

                /**
                 * Try to get all missing files from GCS
                 */
                if ( !file_exists( $file ) ) {
                    $gs_name = str_replace( trailingslashit( $upload_dir[ 'basedir' ] ), '', $file );
                    $gs_name = apply_filters( 'wp_stateless_file_name', $gs_name);
                    ud_get_stateless_media()->get_client()->get_media( $gs_name, true, $file );
                }

                if ( !empty( $meta_data['sizes'] ) && is_array( $meta_data['sizes'] ) ) {
                    foreach( $meta_data['sizes'] as $image ) {
                        $_file = trailingslashit( $upload_dir[ 'basedir' ] ) . trailingslashit(dirname($meta_data['file'])) . $image['file'];
                        if ( !empty( $image['gs_name'] ) && !file_exists( $_file ) ) {
                            ud_get_stateless_media()->get_client()->get_media( apply_filters( 'wp_stateless_file_name', $image['gs_name']), true, $_file );
                        }
                    }
                }

                return $file;
            }

            /**
             * Determine where we hook from
             * We need to do this only for something specific in shortpixel plugin
             *
             * @return bool
             */
            private function hook_from_shortpixel() {
                $call_stack = debug_backtrace();

                if ( !empty( $call_stack ) && is_array( $call_stack ) ) {
                    foreach( $call_stack as $step ) {
                        if ( $step['function'] == 'getURLsAndPATHs' && strpos( $step['file'], 'wp-short-pixel' ) ) {
                            return true;
                        }
                    }
                }

                return false;
            }

            /**
             * If image size not exist then upload it to GS.
             * 
             * $args = array(
             *      'thumbnail' => $thumbnail,
             *      'p_img_large' => $p_img_large,
             *   )
             */
            public function shortpixel_image_optimised($id){

                /**
                 * Restore stateless mode if needed
                 */
                global $wp_stateless_shortpixel_mode;
                if ( $wp_stateless_shortpixel_mode == 'stateless' ) {
                    ud_get_stateless_media()->set( 'sm.mode', 'stateless' );
                }

                $metadata = wp_get_attachment_metadata( $id );
                ud_get_stateless_media()->add_media( $metadata, $id, true );
            }

            /**
             * 
             */
            public function shortpixel_before_restore_image($id, $metadata = null){
                $this->sync_backup_file($id, $metadata, false, true);
            }

            /**
             * Sync backup image
             */
            public function sync_backup_file($id, $metadata = null, $before_optimization = false, $force = false){
                
                /* Get metadata in case if method is called directly. */
                if( empty($metadata) ) {
                    $metadata = wp_get_attachment_metadata( $id );
                }
                /* Now we go through all available image sizes and upload them to Google Storage */
                if( !empty( $metadata[ 'sizes' ] ) && is_array( $metadata[ 'sizes' ] ) ) {

                    // Sync backup file with GCS
                    $file_path = get_attached_file( $id );
                    $fullSubDir = $this->returnSubDir($file_path);
                    $backup_path = SHORTPIXEL_BACKUP_FOLDER . '/' . $fullSubDir;
                    if($before_optimization){
                        $upload_dir = wp_upload_dir();
                        $backup_path = $upload_dir['basedir'] . '/' . dirname($metadata[ 'file' ]);
                    }

                    /**
                     * If mode is stateless then we change it to cdn in order images not being deleted before optimization
                     * Remember that we changed mode via global var
                     */
                    $wp_stateless_shortpixel_mode;
                    
                    if ( ud_get_stateless_media()->get( 'sm.mode' ) == 'stateless' ) {
                        ud_get_stateless_media()->set( 'sm.mode', 'cdn' );
                        $wp_stateless_shortpixel_mode = 'stateless';
                    }

                    $absolutePath = trailingslashit($backup_path) . basename($metadata[ 'file' ]);
                    $name = apply_filters( 'wp_stateless_file_name',  SHORTPIXEL_BACKUP . '/' . $fullSubDir . basename($metadata[ 'file' ]));
                    do_action( 'sm:sync::syncFile', $name, $absolutePath, $force);

                    foreach( (array) $metadata[ 'sizes' ] as $image_size => $data ) {
                        $absolutePath = trailingslashit($backup_path) . $data[ 'file' ];
                        $name = apply_filters( 'wp_stateless_file_name',  SHORTPIXEL_BACKUP . '/' . $fullSubDir . $data[ 'file' ]);
                        
                        do_action( 'sm:sync::syncFile', $name, $absolutePath, $force);
                    }

                    if ( $wp_stateless_shortpixel_mode == 'stateless' ) {
                        ud_get_stateless_media()->set( 'sm.mode', 'stateless' );
                    }

                }
            }

            /**
             * return subdir for that particular attached file - if it's media library then last 3 path items, otherwise substract the uploads path
             * Has trailing directory separator (/)
             * 
             * @copied from shortpixel-image-optimiser\class\db\shortpixel-meta-facade.php
             * @param type $file
             * @return string
             */
            public function returnSubDir($file){
                $hp = wp_normalize_path(get_home_path());
                $file = wp_normalize_path($file);
                $sp__uploads = wp_upload_dir();
                if(strstr($file, $hp)) {
                    $path = str_replace( $hp, "", $file);
                } elseif( strstr($file, dirname( WP_CONTENT_DIR ))) { //in some situations the content dir is not inside the root, check this also (ex. single.shortpixel.com)
                    $path = str_replace( trailingslashit(dirname( WP_CONTENT_DIR )), "", $file);
                } elseif( (strstr(realpath($file), realpath($hp)))) {
                    $path = str_replace( realpath($hp), "", realpath($file));
                } elseif( strstr($file, trailingslashit(dirname(dirname( $sp__uploads['basedir'] )))) ) {
                    $path = str_replace( trailingslashit(dirname(dirname( $sp__uploads['basedir'] ))), "", $file);
                } else {
                    $path = (substr($file, 1));
                }
                $pathArr = explode('/', $path);
                unset($pathArr[count($pathArr) - 1]);
                return implode('/', $pathArr) . '/';
            }

            /**
             * Sync images after shortpixel restore them from backup.
             */
            public function handleRestoreBackup($attachmentID){
                $metadata = wp_get_attachment_metadata( $attachmentID );
                $this->add_media( $metadata, $attachmentID );
            }
            
            /**
             * Customized version of wpCloud\StatelessMedia\Utility::add_media()
             * to satisfied our need in restore backup
             * If a image isn't restored from backup then ignore it.
             */
            public static function add_media( $metadata, $attachment_id ) {
                $upload_dir = wp_upload_dir();

                $client = ud_get_stateless_media()->get_client();

                if( !is_wp_error( $client ) ) {

                    $fullsizepath = wp_normalize_path( get_attached_file( $attachment_id ) );
                    // Make non-images uploadable.
                    if( empty( $metadata['file'] ) && $attachment_id ) {
                        $metadata = array( "file" => str_replace( trailingslashit( $upload_dir[ 'basedir' ] ), '', get_attached_file( $attachment_id ) ) );
                    }

                    $file = wp_normalize_path( $metadata[ 'file' ] );
                    $image_host = ud_get_stateless_media()->get_gs_host();
                    $bucketLink = apply_filters('wp_stateless_bucket_link', $image_host);
                    $_cacheControl = \wpCloud\StatelessMedia\Utility::getCacheControl( $attachment_id, $metadata, null );
                    $_contentDisposition = \wpCloud\StatelessMedia\Utility::getContentDisposition( $attachment_id, $metadata, null );
                    $_metadata = array(
                        "width" => isset( $metadata[ 'width' ] ) ? $metadata[ 'width' ] : null,
                        "height" => isset( $metadata[ 'height' ] )  ? $metadata[ 'height' ] : null,
                        'object-id' => $attachment_id,
                        'source-id' => md5( $attachment_id . ud_get_stateless_media()->get( 'sm.bucket' ) ),
                        'file-hash' => md5( $metadata[ 'file' ] )
                    );

                    if(file_exists($fullsizepath)){
                        $file = apply_filters( 'wp_stateless_file_name', $file);

                        /* Add default image */
                        $media = $client->add_media( $_mediaOptions = array_filter( array(
                            'force' => true,
                            'name' => $file,
                            'absolutePath' => wp_normalize_path( get_attached_file( $attachment_id ) ),
                            'cacheControl' => $_cacheControl,
                            'contentDisposition' => $_contentDisposition,
                            'mimeType' => get_post_mime_type( $attachment_id ),
                            'metadata' => $_metadata
                        ) ));

                        // Stateless mode: we don't need the local version.
                        if(ud_get_stateless_media()->get( 'sm.mode' ) === 'stateless'){
                            unlink($fullsizepath);
                        }
                    }

                    /* Now we go through all available image sizes and upload them to Google Storage */
                    if( !empty( $metadata[ 'sizes' ] ) && is_array( $metadata[ 'sizes' ] ) ) {

                        $path = wp_normalize_path( dirname( get_attached_file( $attachment_id ) ) );
                        $mediaPath = apply_filters( 'wp_stateless_file_name', trim( dirname( $metadata[ 'file' ] ), '\/\\' ) );

                        foreach( (array) $metadata[ 'sizes' ] as $image_size => $data ) {

                            $absolutePath = wp_normalize_path( $path . '/' . $data[ 'file' ] );

                            if( !file_exists($absolutePath)){
                                continue;
                            }
                            
                            /* Add 'image size' image */
                            $media = $client->add_media( array(
                                'force' => true,
                                'name' => $file_path = trim($mediaPath . '/' . $data[ 'file' ], '/'),
                                'absolutePath' => $absolutePath,
                                'cacheControl' => $_cacheControl,
                                'contentDisposition' => $_contentDisposition,
                                'mimeType' => $data[ 'mime-type' ],
                                'metadata' => array_merge( $_metadata, array(
                                    'width' => $data['width'],
                                    'height' => $data['height'],
                                    'child-of' => $attachment_id,
                                    'file-hash' => md5( $data[ 'file' ] )
                                ))
                            ));

                            /* Break if we have errors. */
                            if( !is_wp_error( $media ) ) {
                                // Stateless mode: we don't need the local version.
                                if(ud_get_stateless_media()->get( 'sm.mode' ) === 'stateless'){
                                    unlink($absolutePath);
                                }
                            }

                        }

                    }

                }
            }
            // End add_media
        }

    }

}
