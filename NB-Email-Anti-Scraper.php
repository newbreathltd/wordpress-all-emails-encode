<?php
/*
Plugin Name: NB Email Anti-Scraper
Plugin URI: http://newbreath.co.uk/wordpress-plugins/nb-en_emails-js-encoder/
Description: Plug hides and shows the encoded e-mail addresses on the site. So to your e-mail address is being protected from copying by robots. The user does not see the difference - e-mail is available and clickable.
Version: 1.0.0
Author: New Breath LTD.
Author URI: http://newbreath.co.uk/wordpress-plugins/
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: newbreath
 */

add_filter('the_content', 'nb_email_js_encoder');

function nb_generateRandomString($length = 10) {
	$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	$charactersLength = strlen($characters);
	$randomString = '';
	for ($i = 0; $i < $length; $i++) {
		$randomString .= $characters[rand(0, $charactersLength - 1)];
	}
	return "|" . $randomString . "|";
}

function nb_encodeEmail($email) {
	$oldEmail = $email;
	$email = "";
	$rs = nb_generateRandomString(rand(4, 10));
	// echo "RS: ".$rs."<br />";
	// echo "oldEmail: ".$oldEmail."<br />";
	for ($i = 0; $i < strlen($oldEmail); $i++) {
		$email .= (substr($oldEmail, $i, 1) . $rs);
	}
	// echo "email: ".$email."<br />";
	$encoded = ("nbemail:" . strlen($rs) . ":" . base64_encode($email));
	// echo "Encoded: ". nb_decodeEmail($encoded)."<br />";

	return $encoded;
}

function nb_decodeEmail($encrypted) {
	list($code, $saltL, $encrypted) = explode(":", $encrypted, 3);
	$saltL = (int) $saltL;

	// echo "Encrypted:". $encrypted."\r\n";
	// echo "Decrypted:". base64_decode($encrypted)."\r\n";

	// echo $encrypted."\r\n";
	// echo base64_decode($encrypted)."\r\n";
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

function nb_email_js_encoder($content) {
	$omd5 = md5($content);
	$content = preg_replace_callback("/mailto:[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,4}/", function ($match) {
		// echo "<pre>".print_r($match,true)."</pre>";
		return nb_encodeEmail($match[0]);
		// return str_replace("@","__##__",$match[0]);
	}, $content);
	$content = preg_replace_callback("/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,4}/", function ($match) {
		// echo "<pre>".print_r($match,true)."</pre>";
		return "<span class='nbemail' data-nb-email='" . nb_encodeEmail($match[0]) . "'></span>";
		// return str_replace("@","__##__",$match[0]);
	}, $content);
	$nmd5 = md5($content);
	return $content;
}

add_action('wp_ajax_nb_email', 'nb_decode_ajax');
add_action('wp_ajax_nopriv_nb_email', 'nb_decode_ajax');

function nb_decode_ajax() {
	// print_r($_POST);
	$output = array();
	if (isset($_POST["en_emails"]) && is_array($_POST["en_emails"]) && count($_POST["en_emails"])) {
		// print_R($_POST["en_emails"]);
		foreach ($_POST["en_emails"] as $en_email) {
			$en_email = preg_replace_callback("/[^a-zA-Z0-9\=\,\:]/", function ($match) {
				return "";
			}, $en_email);

			$output[$en_email] = explode("@", nb_decodeEmail($en_email), 2);
		}
	}
	// print_r($_POST);
	echo json_encode($output);
	die();
}

function nb_footer() {
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
		$.post("<?php echo admin_url('admin-ajax.php'); ?>",{action:'nb_email',en_emails:nbemail_addresses},function (output) {
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

add_filter("wp_footer", "nb_footer");

function nb_custom_widget_callback_function() {

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

function nb_email_filter_dynamic_sidebar_params($sidebar_params) {

	if (is_admin()) {
		return $sidebar_params;
	}

	global $wp_registered_widgets;
	$widget_id = $sidebar_params[0]['widget_id'];

	$wp_registered_widgets[$widget_id]['original_callback'] = $wp_registered_widgets[$widget_id]['callback'];
	$wp_registered_widgets[$widget_id]['callback'] = 'nb_custom_widget_callback_function';

	return $sidebar_params;

}
add_filter('dynamic_sidebar_params', 'nb_email_filter_dynamic_sidebar_params');

function nb_widget_output_filter($widget_output, $widget_id_base, $widget_id) {
	return nb_email_js_encoder($widget_output);
}
add_filter('widget_output', 'nb_widget_output_filter', 10, 3);

function nb_comment_text_filter($comment_text) {
	return nb_email_js_encoder($comment_text);
}
add_filter('comment_text', 'nb_comment_text_filter', 10, 3);