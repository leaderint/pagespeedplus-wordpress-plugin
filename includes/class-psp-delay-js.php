<?php
/**
 * Delay JavaScript execution until first user interaction (or a timeout).
 *
 * Scripts are neutralized by switching their type attribute; a tiny inline
 * loader restores them in original order on mousemove / scroll / keydown /
 * touchstart / click, or after N seconds as a fallback. This is the single
 * biggest TBT/TTI win for script-heavy pages.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Delay_JS {

	/**
	 * Scripts that must never be delayed for the page to render.
	 *
	 * @var array
	 */
	private $builtin_excludes = array(
		'psp-delay-loader',
		'psp-yt-facade',
		'psp-rum',
		'application/ld+json',
		'application/json',
		'text/template',
		'lazyload',
		'psp-lazy',
	);

	public function __construct() {
		if ( PSP_Options::get( 'delay_js' ) ) {
			add_filter( 'psp_buffer', array( $this, 'delay' ), 30 );
		}
	}

	/**
	 * @param string $html Page HTML.
	 * @return string
	 */
	public function delay( $html ) {
		$excludes = array_merge( $this->builtin_excludes, PSP_Options::get_lines( 'delay_js_exclude' ) );

		$html = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			function ( $m ) use ( $excludes ) {
				list( $full, $attrs, $body ) = $m;

				// Skip non-JS types (JSON-LD, templates) and anything excluded.
				if ( preg_match( '/type=["\'](?!text\/javascript|module)[^"\']*["\']/i', $attrs ) ) {
					return $full;
				}
				if ( PSP_Assets::is_excluded( $full, $excludes ) ) {
					return $full;
				}

				// Neutralize: browsers ignore unknown script types.
				if ( preg_match( '/type=["\'][^"\']*["\']/i', $attrs ) ) {
					$attrs = preg_replace( '/type=["\'][^"\']*["\']/i', 'type="psp/delayed"', $attrs );
				} else {
					$attrs = ' type="psp/delayed"' . $attrs;
				}
				// async/defer would be re-applied on restore and break ordering.
				$attrs = preg_replace( '/\s+\b(async|defer)(=["\'][^"\']*["\'])?/i', '', $attrs );

				return '<script' . $attrs . '>' . $body . '</script>';
			},
			$html
		);

		return $this->inject_loader( $html );
	}

	/**
	 * Inject the restore loader right before </body>.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private function inject_loader( $html ) {
		$timeout = max( 0, (int) PSP_Options::get( 'delay_js_timeout' ) ) * 1000;

		$loader = <<<'JS'
(function(){
"use strict";
var fired=false,events=["keydown","mousemove","wheel","touchstart","touchmove","click"],t;
function load(){
	if(fired)return;fired=true;
	events.forEach(function(e){window.removeEventListener(e,load,{passive:true});});
	if(t)clearTimeout(t);
	var scripts=Array.prototype.slice.call(document.querySelectorAll('script[type="psp/delayed"]'));
	(function next(){
		var old=scripts.shift();
		if(!old)return done();
		var s=document.createElement("script");
		for(var i=0;i<old.attributes.length;i++){
			var a=old.attributes[i];
			if(a.name!=="type")s.setAttribute(a.name,a.value);
		}
		s.type="text/javascript";
		if(old.src){
			s.onload=s.onerror=next;
			s.src=old.src;
		}else{
			s.textContent=old.textContent;
		}
		old.parentNode.replaceChild(s,old);
		if(!s.src)next();
	})();
	function done(){
		// Re-fire lifecycle events frameworks listen for.
		document.dispatchEvent(new Event("DOMContentLoaded",{bubbles:true}));
		window.dispatchEvent(new Event("load"));
		window.dispatchEvent(new Event("psp:scriptsLoaded"));
	}
}
events.forEach(function(e){window.addEventListener(e,load,{passive:true});});
TIMEOUT
})();
JS;

		$timeout_code = $timeout > 0 ? "t=setTimeout(load,{$timeout});" : '';
		$loader       = str_replace( 'TIMEOUT', $timeout_code, $loader );

		$tag = '<script id="psp-delay-loader">' . $loader . '</script>';

		if ( false !== stripos( $html, '</body>' ) ) {
			return preg_replace( '/<\/body>/i', $tag . '</body>', $html, 1 );
		}
		return $html . $tag;
	}
}
