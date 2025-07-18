<?php
namespace Sharepicgenerator\Controllers;

use Sharepicgenerator\Controllers\User;
use Sharepicgenerator\Controllers\Logger;

/**
 * Sharepic controller.
 */
class Sharepic {

	/**
	 * The file to write to.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * The HTML for the sharepic.
	 *
	 * @var string
	 */
	private $html;

	/**
	 * The template to be loaded
	 *
	 * @var string
	 */
	private $template;

	/**
	 * The size of the sharepic.
	 *
	 * @var array
	 */
	private $size = array(
		'width'  => 100,
		'height' => 100,
	);

	/**
	 * Env variable like user, config, mailer, etc..
	 *
	 * @var object
	 */
	private $env;


	/**
	 * Infos about the sharepic, like name.
	 *
	 * @var string
	 */
	private $info;

	/**
	 * Path to the users workspace.
	 *
	 * @var string
	 */
	private $workspace;

	/**
	 * Saving or publishing.
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Raw data.
	 *
	 * @var string
	 */
	private $raw_data;

	/**
	 * Path to save sharepic
	 *
	 * @var string
	 */
	private $path;

	/**
	 * The body class.
	 *
	 * @var string
	 */
	private $body_class;

	/**
	 * The format of the output.
	 *
	 * @var string
	 */
	private $format;


	/**
	 * The constructor. Reads the inputs, stores information.
	 *
	 * @param Env $env Environment with user, config, logger, mailer, etc.
	 */
	public function __construct( $env ) {
		$this->env = $env;

		$this->workspace = $this->env->user->get_dir() . 'workspace';
		if ( ! file_exists( $this->workspace ) ) {
			mkdir( $this->workspace );
		}
		$this->file = $this->workspace . '/sharepic.html';

		$data = json_decode( file_get_contents( 'php://input' ), true );

		if ( empty( $data ) ) {
			return;
		}

		$this->size['width']  = (int) ( $data['size']['width'] ?? 100 );
		$this->size['height'] = (int) ( $data['size']['height'] ?? 100 );
		$this->size['zoom']   = (float) ( $data['size']['zoom'] ?? 1 );
		$this->path           = (int) ( $data['path'] ?? 0 );
		$this->format         = (string) ( $data['format'] ?? 'png' );
		if ( ! in_array( $this->format, array( 'png', 'jpg', 'spg' ) ) ) {
			$this->format = 'png';
		}
		$this->template       = ( isset( $data['template'] ) ) ? $data['template'] : $this->file;
		$this->info           = ( isset( $data['name'] ) ) ? preg_replace( '/[^a-zA-Z0-9 äöüÄÖÜß:\-\.]/', ':', $data['name'] ) : 'no-name';
		$this->mode           = ( isset( $data['mode'] ) && in_array( $data['mode'], array( 'save', 'publish', 'bug' ) ) ) ? $data['mode'] : 'save';
		$this->raw_data       = $data['data'] ?? '';
		$this->body_class     = ( isset( $data['body_class'] ) ) ? Helper::sanitze_az09( $data['body_class'] ) : '';

		if ( str_starts_with( $this->template, 'save' ) ) {
			$this->template = $this->env->user->get_dir() . $this->template;
		}
	}

	/**
	 * Resizes the output.
	 *
	 * @param float $zoom The HTML to be rewritten.
	 */
	private function set_zoom( $zoom ) {
		$this->html = '<style class="server-only">body{ margin: 0; padding: 0;} #sharepic{ zoom: ' . $zoom . '; }</style>' . $this->html;
	}

	/**
	 * Saves the sharepic.
	 */
	public function save() {
		$workspace = $this->env->user->get_dir() . 'workspace/';
		$save_dir  = $this->env->user->get_dir() . 'save/';

		if ( 'publish' === $this->mode ) {
			$save_dir = 'public_savings/';
		}

		if ( 'bug' === $this->mode ) {
			$save_dir = $this->env->user->get_dir() . 'bug/';
		}

		$id   = ( $this->path > 0 ) ? $this->path : rand( 1000000, 9999999 );
		$save = $save_dir . $id;

		// Autosaved sharepics do not have a real thumbnail. Provide a dummy thumbnail.
		if ( $id === 1 ) {
			copy( 'assets/transparent.png', $workspace . 'thumbnail.png' );
		}

		$save_count = count( glob( $save_dir . '/*', GLOB_ONLYDIR ) );
		if ( 'save' === $this->mode && $save_count > 30 ) {
			$this->http_error( 'Too many files' );
		}

		if ( ! file_exists( $save_dir ) ) {
			mkdir( $save_dir );
		}

		$cmd = "rm -rf $save 2>&1";
		exec( $cmd, $output, $return_code );

		$cmd = "cp -R $workspace $save 2>&1";
		exec( $cmd, $output, $return_code );
		$this->env->logger->access( 'Execute command: ' . $cmd );
		if ( 0 !== $return_code ) {
			$this->env->logger->error( implode( "\n", $output ) );
			$this->http_error( 'Could not save sharepic' );
		}

		// Write HTML-File.
		file_put_contents( $save . '/sharepic.html', $this->raw_data );

		// Write info file.
		file_put_contents(
			$save . '/info.json',
			json_encode(
				array(
					'name'  => $this->info,
					'owner' => $this->env->user->get_username(),
				)
			)
		);

		echo json_encode(
			array(
				'full_path'  => $save . '/sharepic.html',
				'id'         => $id,
				'save_count' => $save_count,
			)
		);
		return true;
	}

	/**
	 * Deletes a sharepic.
	 */
	public function delete() {
		$data = json_decode( file_get_contents( 'php://input' ), true );

		$sharepic = $data['saving'] ?? false;

		if ( ! $sharepic ) {
			$this->http_error( 'Could not delete sharepic. (1)' );
		}

		// The casting to int is necessary to prevent directory traversal.
		if ( '1' == $data['publicSharepic'] ) {
			$save_dir = 'public_savings/' . (int) $sharepic;

			if ( $this->env->user->get_username() !== json_decode( file_get_contents( $save_dir . '/info.json' ) )->owner ) {
				$this->http_error( 'Could not delete sharepic (3)' );
			}
		} else {
			$save_dir = $this->env->user->get_dir() . '/save/' . (int) $sharepic;
		}

		if ( ! file_exists( $save_dir ) ) {
			$this->http_error( 'Could not find sharepic: ' . $save_dir );
		}

		$cmd = sprintf( 'rm -rf %s 2>&1', escapeshellarg( $save_dir ) );
		exec( $cmd, $output, $return_code );
		if ( 0 !== $return_code ) {
			$this->env->logger->error( implode( "\n", $output ) );
			$this->http_error( 'Could not delete sharepic (2)' );
		}

		echo json_encode( array( 'success' => true ) );
	}

	/**
	 * Creates a sharepic by taking the screenshot of the HTML.
	 */
	public function create() {
		$output_file = 'output.png';
		$path        = $this->env->user->get_dir() . $output_file;
		$config      = new Config();

		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		// mb_convert_encoding is said to be deprecated, but not in the docs.
		$doc->loadHTML( @mb_convert_encoding( $this->raw_data, 'HTML-ENTITIES', 'UTF-8' ) );
		libxml_clear_errors();
		$this->html = filter_var( $doc->saveHTML(), FILTER_DEFAULT, FILTER_FLAG_STRIP_LOW );

		$this->set_zoom( 1 / $this->size['zoom'] );

		$scaffold = '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"></head><body class="%s">%s</body></html>';
		file_put_contents( $this->file, sprintf( $scaffold, $this->body_class, $this->html ) );

		$cmd_prepend = ( 'local' === $this->env->config->get( 'Main', 'env' ) ) ? 'sudo' : '';

		$cmd = sprintf(
			'%s google-chrome --no-sandbox --headless --disable-gpu --screenshot=%s --hide-scrollbars --window-size=%d,%d %s 2>/dev/null',
			$cmd_prepend,
			$path,
			(int) $this->size['width'],
			(int) $this->size['height'] + 87, // Add height for google chrome toolbar (since chrome 128).
			escapeshellarg( $this->file )
		);

		exec( $cmd, $output, $return_code );
		$this->env->logger->access( 'Command executed: ' . $cmd . ' ' . implode( "\n", $output ) );

		if ( 0 !== $return_code ) {
			$this->env->logger->error( implode( "\n", $output ) );
			$this->http_error( 'Could not create file 1' );
		}

		// Remove the toolbar added by google chrome since version 128.
		$cmd = sprintf(
			'%1$s convert %2$s -gravity South -chop 0x87 %2$s 2>&1',
			$cmd_prepend,
			$path
		);
		exec( $cmd, $output, $return_code );
		$this->env->logger->access( 'Command executed: ' . $cmd . ' ' . implode( "\n", $output ) );

		if ( 0 !== $return_code ) {
			$this->env->logger->error( implode( "\n", $output ) );
			$this->http_error( 'Could not create file 2' );
		}

		if ( 'jpg' === $this->format ) {
			$this->convert_to_jpg( $path );
			$output_file = substr( $output_file, 0, -3 ) . 'jpg';
		}

		if ( 'spg' === $this->format ) {
			$this->env->logger->access( 'Creating SPG' );

			$output_file = substr( $output_file, 0, -3 ) . 'spg';

			$cmd = sprintf(
				'%s zip %s %s -x */info.json */thumbnail.png 2>&1',
				$cmd_prepend,
				$this->env->user->get_dir() . $output_file,
				$this->env->user->get_dir() . 'save/2/*',
				$output_file
			);

			exec( $cmd, $output, $return_code );
			if ( 0 !== $return_code ) {
				$this->env->logger->error( 'ZIP ERROR@@@@@' . implode( "\n", $output ) . $return_code );
			}
			$this->env->logger->access( 'Command executed: ' . $cmd . ' ' . implode( "\n", $output ) );

		} else {
			// thumbnail and qrcode are only created for png and jpg.
			$this->create_thumbnail( $path );
			$this->create_qrcode( $path );
		}
		echo json_encode( array( 'path' => 'index.php?c=proxy&r=' . rand( 1, 999999 ) . '&p=' . $output_file ) );
	}

	/**
	 * Loads an image from a URL.
	 */
	public function load_from_url() {
		$data = json_decode( file_get_contents( 'php://input' ), true );

		$url = filter_var( $data['url'], FILTER_VALIDATE_URL );

		if ( ! $url ) {
			$this->http_error( 'Could not load image (code 1)' );
			return;
		}

		$image_type = Helper::is_image_file_remote( $url );
		if ( ! $image_type ) {
			$this->http_error( 'Could not load image (code 2)' );
			return;
		}

		// $extension = strtolower( pathinfo( $url, PATHINFO_EXTENSION ) );
		// if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif' ) ) ) {
		// $this->http_error( 'Could not load image (code 5)' );
		// return;
		// }

		if ( ! in_array( $image_type, array( 'jpg', 'jpeg', 'png', 'gif' ) ) ) {
			$this->http_error( 'Could not load image (code 4)' );
			return;
		}

		$extension = $image_type;

		$upload_file = $this->env->user->get_dir() . 'workspace/background.' . $extension;

		copy( $url, $upload_file );
		$this->env->logger->access( 'Loading image from URL ' . $url . ' to ' . $upload_file );

		if ( ! Helper::is_image_file_local( $upload_file ) ) {
			unlink( $upload_file );
			$this->http_error( 'Could copy load image' );
			return;
		}

		if ( 'local' === $this->env->config->get( 'Main', 'env' ) ) {
			$this->reduce_filesize( $upload_file, 800, 90 );
		} else {
			$this->reduce_filesize( $upload_file );
		}

		echo json_encode( array( 'path' => 'index.php?c=proxy&r=' . rand( 1, 999999 ) . '&p=workspace/background.' . $extension ) );
	}

	/**
	 * Converts an image to jpg.
	 *
	 * @param string $path The path to the image.
	 */
	private function convert_to_jpg( $path ) {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( 'jpg' === $extension ) {
			return;
		}

		$cmd = sprintf(
			'convert %s %s 2>&1',
			$path,
			substr( $path, 0, -3 ) . 'jpg'
		);

		exec( $cmd, $output, $return_code );
		$this->env->logger->access( 'Command executed: ' . $cmd . ' ' . implode( "\n", $output ) );

		if ( 0 !== $return_code ) {
			$this->env->logger->error( $cmd . ' OUTPUT=' . implode( "\n", $output ) );
			$this->http_error( 'Could not convert image' );
		}
	}

	/**
	 * Creates a QR code.
	 *
	 * @param string $path The path to the image.
	 */
	private function create_qrcode( $path ) {
		$random_filename = bin2hex( random_bytes( 16 ) ) . '.png';

		copy( $path, 'qrcodes/' . $random_filename );

		$qrcode_file = $this->env->user->get_dir() . 'qrcode.png';
		$qrcode_url  = 'https://' . $_SERVER['HTTP_HOST'] . '/qrcodes/' . $random_filename;

		$cmd = sprintf(
			'qrencode -s 4 -o %s %s 2>&1',
			$qrcode_file,
			$qrcode_url
		);

		exec( $cmd, $output, $return_code );

		$this->env->logger->access( 'Command executed: ' . $cmd . ' ' . implode( "\n", $output ) );
		if ( 0 !== $return_code ) {
			$this->env->logger->error( implode( "\n", $output ) );
		}
	}

	/**
	 * Creates a thumbnail and saves it to the tmp folder and workspace.
	 *
	 * @param string $path The path to the image.
	 */
	private function create_thumbnail( $path ) {
		$thumbnail = bin2hex( random_bytes( 16 ) ) . '.png';
		$cmd       = sprintf(
			'convert %s -resize 400x400 ../tmp/%s 2>&1',
			$path,
			$thumbnail
		);

		exec( $cmd, $output, $return_code );
		$this->env->logger->access( 'Command executed: ' . $cmd . ' ' . implode( "\n", $output ) );
		if ( 0 !== $return_code ) {
			$this->env->logger->error( $cmd . ' OUTPUT=' . implode( "\n", $output ) );
			$this->http_error( 'Could not create thumbnail' );
		}

		$cmd = sprintf(
			'cp ../tmp/%s %s 2>&1',
			$thumbnail,
			$this->env->user->get_dir() . 'workspace/thumbnail.png'
		);
		exec( $cmd, $output, $return_code );
		$this->env->logger->access( 'Command executed: ' . $cmd . ' ' . implode( "\n", $output ) );
		if ( 0 !== $return_code ) {
			$this->env->logger->error( $cmd . ' OUTPUT=' . implode( "\n", $output ) );
			$this->http_error( 'Could not copy thumbnail' );
		}
	}

	/**
	 * Loads the sharepic.
	 *
	 * @throws \Exception If the file does not exist.
	 */
	public function load() {
		try {
			$real_path    = realpath( $this->template );
			$template_dir = realpath( dirname( __DIR__, 2 ) ) . '/templates/';
			$public_dir   = realpath( dirname( __DIR__, 2 ) ) . '/public_savings/';
			$user_dir     = realpath( $this->env->user->get_dir() );

			if ( ! $real_path ) {
				throw new \Exception( 'File does not exist' );
			}

			// Do only load from template or user directory.
			if ( ! str_starts_with( $real_path, $template_dir ) && ! str_starts_with( $real_path, $public_dir ) && ! str_starts_with( $real_path, $user_dir ) ) {
				throw new \Exception( 'File may not be served' );
			}

			// If the file is in the user directory or in public sharepics (it is saved), copy all files to workspace.
			if ( str_starts_with( $real_path, $user_dir ) || str_starts_with( $real_path, $public_dir ) ) {
				$workspace = $user_dir . '/workspace';

				$cmd = sprintf(
					'cp -R %s/* %s 2>&1',
					dirname( $real_path ),
					$workspace
				);

				exec( $cmd, $output, $return_code );
				$this->env->logger->access( 'Command executed: ' . $cmd . ' ' . implode( "\n", $output ) );

				if ( 0 !== $return_code ) {
					$this->env->logger->alarm( $cmd . ' OUTPUT=' . implode( "\n", $output ) );
					$this->http_error( 'Could not copy files' );
				}
			}

			$this->delete_unused_files();

			echo file_get_contents( $this->template );
		} catch ( \Exception $e ) {
			$this->env->logger->alarm( $this->template . ' ' . $e->getMessage() );
			$this->http_error( 'Could not load file ' );
		}
	}

	/**
	 * Uploads an images
	 */
	public function upload() {
		if ( ! isset( $_FILES['file'] ) ) {
			return;
		}

		if ( is_array( $_FILES['file']['name'] ) ) {
			$this->env->logger->alarm( 'Multiple files uploaded' );
			$this->http_error( 'Could not upload file' );
		}

		$file = $_FILES['file'];

		$extension   = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		$upload_file = $this->env->user->get_dir() . 'workspace/background.' . $extension;

		if ( ! move_uploaded_file( $file['tmp_name'], $upload_file ) ) {
			$this->http_error( 'Could not upload file. Code 1.' );
		}

		$this->env->logger->access( 'Uploaded image to ' . $upload_file );

		if ( Helper::is_spg_file( $upload_file ) ) {

			$cmd = sprintf(
				'%s unzip -j -o %s/background.spg -d %s2',
				( 'local' === $this->env->config->get( 'Main', 'env' ) ) ? 'sudo' : '',
				$this->env->user->get_dir() . 'workspace/',
				$this->env->user->get_dir() . 'save/'
			);

			exec( $cmd, $output, $return_code );
			$this->env->logger->access( 'Command executed: ' . $cmd . ' ' . implode( "\n", $output ) );

			if ( 0 !== $return_code ) {
				$this->env->logger->error( implode( "\n", $output ) );
				$this->http_error( 'Error. Code 6.' );
			}

			$cmd = sprintf(
				'%s rm %s/background.spg',
				( 'local' === $this->env->config->get( 'Main', 'env' ) ) ? 'sudo' : '',
				$this->env->user->get_dir() . 'workspace/',
			);

			exec( $cmd, $output, $return_code );
			$this->env->logger->access( 'Command executed: ' . $cmd . ' ' . implode( "\n", $output ) );

			if ( 0 !== $return_code ) {
				$this->env->logger->error( implode( "\n", $output ) );
				$this->http_error( 'Error. Code 7.' );
			}

			echo json_encode( array( 'spg' => '1' ) );
			return;
		}

		if ( ! Helper::is_image_file_local( $upload_file ) ) {
			unlink( $upload_file );
			$this->http_error( 'Could not upload image. Code 4.' );
			return;
		}

		$this->reduce_filesize( $upload_file );

		echo json_encode( array( 'path' => 'index.php?c=proxy&r=' . rand( 1, 999999 ) . '&p=workspace/background.' . $extension ) );
	}

	/**
	 * Uploads an addpic
	 */
	public function upload_addpic() {
		if ( ! isset( $_FILES['file'] ) ) {
			return;
		}

		if ( is_array( $_FILES['file']['name'] ) ) {
			$this->env->logger->alarm( 'Multiple files uploaded' );
			$this->http_error( 'Could not upload file' );
		}

		$file = $_FILES['file'];

		$extension     = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		$raw_file_path = 'workspace/addpic-' . rand() . '.' . $extension;
		$upload_file   = $this->env->user->get_dir() . $raw_file_path;

		if ( ! move_uploaded_file( $file['tmp_name'], $upload_file ) ) {
			$this->http_error( 'Could not upload file' );
		}

		if ( ! Helper::is_image_file_local( $upload_file ) ) {
			unlink( $upload_file );
			$this->http_error( 'Could not upload image. Code 3.' );
			return;
		}

		$this->reduce_filesize( $upload_file, 2000, 1000 );

		$return = array(
			'path' => 'index.php?c=proxy&r=' . rand( 1, 999999 ) . '&p=' . $raw_file_path,
		);

		$this->env->logger->access( json_encode( $return ) );

		echo json_encode( $return );
	}

	/**
	 * Deletes unused files from workspace.
	 */
	private function delete_unused_files() {
		$file = $this->env->user->get_dir() . 'workspace/sharepic.html';
		if ( ! file_exists( $file ) ) {
			return;
		}
		$html = file_get_contents( $file );

		$available_files = glob( $this->workspace . '/*.{jpg,jpeg,png,gif}', GLOB_BRACE );
		foreach ( $available_files as $file ) {
			if ( ! str_contains( $html, basename( $file ) ) ) {
				unlink( $file );
				$this->env->logger->access( 'Deleted ' . $file );
			}
		}
	}

	/**
	 * Reduces the filesize of an image.
	 *
	 * @param string $file The file to reduce.
	 * @param int    $max_pixels The maximum number of pixels.
	 * @param int    $max_filesize The maximum filesize in kb.
	 */
	private function reduce_filesize( $file, $max_pixels = 4500, $max_filesize = 3000 ) {
		if ( filesize( $file ) < $max_filesize * 1024 ) {
			return;
		}

		$file = escapeshellarg( $file );
		$cmd  = sprintf(
			'mogrify -resize %1$dx%1$d  -define jpeg:extent=%2$dkb %3$s 2>&1',
			(int) $max_pixels,
			(int) $max_filesize,
			$file
		);

		exec( $cmd, $output, $return_code );
		$this->env->logger->access( 'Command executed: ' . $cmd . ' ' . implode( "\n", $output ) );
		if ( 0 !== $return_code ) {
			$this->env->logger->error( implode( "\n", $output ) );
			$this->http_error( 'Could convert image' );
		}
	}

	/**
	 * Just says hello, to check if the user is still logged in.
	 */
	public function is_logged_in() {
		echo json_encode( array( 'status' => 200 ) );
	}

	/**
	 * Saves user config.
	 * Might be in User class, but user class is hidden
	 * from the public for security reasons.
	 */
	public function save_config() {
		$data = json_decode( file_get_contents( 'php://input' ), true );

		if ( empty( $data ) ) {
			return;
		}

		$config_file = $this->env->user->get_dir() . 'config.json';
		$new_config  = array( 'palette' => $data['palette'], 'terms_accepted' => $data['terms_accepted'] );

		$config_data = json_encode( $new_config );
		file_put_contents( $config_file, $config_data );

		echo json_encode( array( 'status' => 200 ) );
	}

	/**
	 * Loads public sharepics
	 */
	public function load_public_sharepics() {
		$templates = glob( 'public_savings/*' );

		$return = array( 'status' => 200, 'images' => [] );
		foreach ( $templates as $dir ) {
			$info      = json_decode( file_get_contents( $dir . '/info.json' ) );
			$id        = basename( $dir );
			$thumbnail = $dir . '/thumbnail.png';

			$return['images'][] = array(
				'id'        => $id,
				'name'      => $info->name,
				'owner'     => $info->owner,
				'thumbnail' => $thumbnail,
			);
		}

		echo json_encode( $return );
	}

	/**
	 * Fail gracefully.
	 *
	 * @param string $message The error message.
	 */
	private function http_error( $message = 'Something went wrong' ) {
		header( 'HTTP/1.0 500 ' . $message );
		die();
	}

	/**
	 * Fail gracefully
	 *
	 * @param mixed $name Method.
	 * @param mixed $arguments Arguments.
	 * @return void
	 */
	public function __call( $name, $arguments ) {
		header( 'HTTP/1.0 404 No route.' );
		die();
	}
}
