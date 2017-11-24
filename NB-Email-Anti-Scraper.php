<?php
/*
Plugin Name: NB Email Anti-Scraper
Plugin URI: http://newbreath.co.uk/wordpress-plugins/nb-en_emails-js-encoder/
Description: Plug hides and shows the encoded e-mail addresses on the site. So to your e-mail address is being protected from copying by robots. The user does not see the difference - e-mail is available and clickable.
Version: 1.0.1
Author: New Breath LTD.
Author URI: http://newbreath.co.uk/wordpress-plugins/
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: newbreath
 */

class nb_email_anti_scrapper {

	function __construct() {
		add_filter('the_content', array($this, 'email_js_encoder'));
		add_action('wp_ajax_nb_email', array($this, 'decode_ajax'));
		add_action('wp_ajax_nopriv_nb_email', array($this, 'decode_ajax'));
		add_filter("wp_footer", array($this, 'footer_script'));
		add_filter('dynamic_sidebar_params', array($this, 'email_filter_dynamic_sidebar_params'));
		add_filter('widget_output', array($this, 'widget_output_filter'), 10, 3);
		add_filter('comment_text', array($this, 'comment_text_filter'), 10, 1);
	}

	function generate_random_string($length = 10) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$charactersLength = strlen($characters);
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
			$randomString .= $characters[rand(0, $charactersLength - 1)];
		}
		return "|" . $randomString . "|";
	}

	function encode_email($email) {
		if (substr($email, 0, strlen("mailto:")) == "mailto:") {
			$oldEmail = "mailto:" . sanitize_email(substr($email, strlen("mailto:")));
		} else {
			$oldEmail = sanitize_email($email);
		}
		$email = "";
		$rs = $this->generate_random_string(rand(4, 10));

		for ($i = 0; $i < strlen($oldEmail); $i++) {
			$email .= (substr($oldEmail, $i, 1) . $rs);
		}

		$encoded = ("nbemail:" . strlen($rs) . ":" . base64_encode($email));

		return $encoded;
	}

	function decode_email($encrypted) {
		list($code, $saltL, $encrypted) = explode(":", $encrypted, 3);
		$saltL = (int) $saltL;

		$email = "";
		switch ($code) {
			case "nbemail":
			default:
				$email_enc = base64_decode($encrypted);
				$salt = substr($email_enc, 1, $saltL);
				$a_email = explode(":", str_replace($salt, "", $email_enc), 2);
				$email = is_email(sanitize_email(end($a_email)));
				if (!$email) {
					return "";
				}

				if (strtolower($a_email[0]) == "mailto") {
					$email = "mailto:" . $email;
				}
				break;
		}
		return $email;
	}
	function email_js_encoder($content) {
		$omd5 = md5($content);
		$content = preg_replace_callback("/mailto:[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,4}/", function ($match) {

			return $this->encode_email($match[0]);

		}, $content);
		$content = preg_replace_callback("/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,4}/", function ($match) {

			return "<span class='nbemail' data-nb-email='" . $this->encode_email($match[0]) . "'></span>";

		}, $content);
		$nmd5 = md5($content);
		return $content;
	}

	function decode_ajax() {
		check_ajax_referer("nbemailnonce_" . md5_file(__FILE__), 'secure');
		$output = array();
		if (isset($_POST["en_emails"]) && is_array($_POST["en_emails"]) && count($_POST["en_emails"])) {
			foreach ($_POST["en_emails"] as $encoded_email) {
				$encoded_email = preg_replace_callback("/[^a-zA-Z0-9\=\,\:]/", function ($match) {
					return "";
				}, $encoded_email);

				$output[$encoded_email] = explode("@", $this->decode_email($encoded_email), 2);
			}
		}

		wp_send_json($output);
		die();
	}

	function footer_script() {
		$ajax_nonce = wp_create_nonce("nbemailnonce_" . md5_file(__FILE__));

		?>
		<style type='text/css'>
			.nbemail { color:inherit;background:inherit; text-decoration: inherit; font:inherit;}
		</style>
		<script type='text/javascript'>
		var nbemail_addresses = [];
		jQuery(function ($) {

			$("a[href^='nbemail:']").each(function (k,v) {
				nbemail_addresses.push($(this).attr("href"));
			});
			$("span[data-nb-email^='nbemail:']").each(function (k,v) {
				nbemail_addresses.push($(this).attr("data-nb-email"));
			});
			$.post("<?php echo admin_url('admin-ajax.php'); ?>",{action:'nb_email',en_emails:nbemail_addresses, secure:'<?php echo $ajax_nonce; ?>'},function (output) {
				$("a[href^='nbemail:']").each(function (k,v) {
					if (typeof output[jQuery(this).attr("href")] != undefined) {
						$(this).attr("href",output[jQuery(this).attr("href")].join("@"));
					}
				});
				$("span[data-nb-email^='nbemail:']").each(function (k,v) {
					if (typeof output[jQuery(this).attr("data-nb-email")] != undefined) {
						$(this).text(output[jQuery(this).attr("data-nb-email")].join("@"));
					}
				});
			},'json');
		});
		</script>
		<?php
}

	function custom_widget_callback_function() {

		global $wp_registered_widgets;
		$original_callback_params = func_get_args();
		$widget_id = $original_callback_params[0]['widget_id'];

		$original_callback = $wp_registered_widgets[$widget_id]['original_callback'];
		$wp_registered_widgets[$widget_id]['callback'] = $original_callback;

		$widget_id_base = $wp_registered_widgets[$widget_id]['callback'][0]->id_base;

		if (is_callable($original_callback)) {

			ob_start();
			call_user_func_array($original_callback, $original_callback_params);
			$widget_output = ob_get_clean();

			echo apply_filters('widget_output', $widget_output, $widget_id_base, $widget_id);

		}

	}

	function email_filter_dynamic_sidebar_params($sidebar_params) {

		if (is_admin()) {
			return $sidebar_params;
		}

		global $wp_registered_widgets;
		$widget_id = $sidebar_params[0]['widget_id'];

		$wp_registered_widgets[$widget_id]['original_callback'] = $wp_registered_widgets[$widget_id]['callback'];
		$wp_registered_widgets[$widget_id]['callback'] = array($this, 'custom_widget_callback_function');

		return $sidebar_params;

	}

	function widget_output_filter($widget_output, $widget_id_base, $widget_id) {
		return $this->email_js_encoder($widget_output);
	}

	function comment_text_filter($comment_text) {
		return $this->email_js_encoder($comment_text);
	}

}

new nb_email_anti_scrapper();
