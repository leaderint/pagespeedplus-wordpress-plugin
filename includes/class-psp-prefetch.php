<?php
/**
 * Link prefetching (instant.page-style perceived-instant navigation).
 *
 * On hover (desktop, with an intent delay) or touchstart (mobile) the next
 * page is prefetched, so by the time the visitor clicks it's already in the
 * browser cache. Pairs especially well with our page cache — the prefetched
 * URL is a static cached file, so it returns in a few ms.
 *
 * The loader is injected after the delay-JS pass (priority 60 > 30) so it is
 * never neutralized, and it's baked into cached HTML like any other markup.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Prefetch {

	public function __construct() {
		if ( PSP_Options::get( 'prefetch_links' ) ) {
			add_filter( 'psp_buffer', array( $this, 'inject' ), 60 );
		}
	}

	/**
	 * @param string $html Page HTML.
	 * @return string
	 */
	public function inject( $html ) {
		// User-supplied path fragments to never prefetch, as a JSON array.
		$excludes = wp_json_encode( PSP_Options::get_lines( 'prefetch_exclude' ) );

		$js = <<<'JS'
(function(){
"use strict";
if(!document.querySelector||!window.URL)return;
var conn=navigator.connection;
if(conn&&(conn.saveData||/(^|-)2g$/.test(conn.effectiveType||"")))return;
var done={},count=0,MAX=50,timer,lastTouch=0,EX=__EXCLUDES__;
function ok(a){
	if(!a||!a.href||a.protocol&&a.protocol.indexOf("http")!==0)return false;
	var u;try{u=new URL(a.href)}catch(e){return false}
	if(u.origin!==location.origin)return false;
	if(u.pathname===location.pathname&&u.search===location.search)return false;
	if(a.hasAttribute("download"))return false;
	if(/nofollow/i.test(a.getAttribute("rel")||""))return false;
	if(a.getAttribute("data-no-prefetch")!==null)return false;
	if(u.search)return false;
	var p=u.pathname;
	if(/\/wp-admin|\/wp-login|\/wp-json|\/cart|\/checkout|\/my-account|\/account|\/feed/i.test(p))return false;
	if(done[u.href])return false;
	for(var i=0;i<EX.length;i++){if(EX[i]&&p.indexOf(EX[i])>-1)return false;}
	return u.href;
}
function go(href){
	if(count>=MAX||done[href])return;done[href]=1;count++;
	var l=document.createElement("link");l.rel="prefetch";l.href=href;l.as="document";
	document.head.appendChild(l);
}
document.addEventListener("mouseover",function(e){
	if(Date.now()-lastTouch<1100)return;
	var a=e.target.closest?e.target.closest("a"):null;if(!a)return;
	var href=ok(a);if(!href)return;
	a.addEventListener("mouseout",function(){if(timer)clearTimeout(timer)},{passive:true,once:true});
	timer=setTimeout(function(){go(href)},65);
},{capture:true,passive:true});
document.addEventListener("touchstart",function(e){
	lastTouch=Date.now();
	var a=e.target.closest?e.target.closest("a"):null;if(!a)return;
	var href=ok(a);if(href)go(href);
},{capture:true,passive:true});
})();
JS;

		$js  = str_replace( '__EXCLUDES__', $excludes ? $excludes : '[]', $js );
		$tag = '<script id="psp-prefetch">' . $js . '</script>';

		if ( false !== stripos( $html, '</body>' ) ) {
			return preg_replace( '/<\/body>/i', $tag . '</body>', $html, 1 );
		}
		return $html . $tag;
	}
}
