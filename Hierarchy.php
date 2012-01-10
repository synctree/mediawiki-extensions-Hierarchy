<?php
// Hierarchy MediaWiki extension.
// Creates a hierarchical page navigation structure.
 
// Copyright (C) 2007, Benner Sistemas.
//
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 
// NOTE: Mark Hechim changed this 2009-06-16 so that it doesn't generate 
// carriage returns or <hr> tags after generated elements.  This will
// allow one to have greater control over formatting (putting them in tables, etc.).
 
$HierarchyVersion = '1.2.1';
 
#----------------------------------------------------------------------------
#      Internationalized messages
#----------------------------------------------------------------------------
 
$wgHierarchyPrefix = "hierarchy_";
$wgHierarchyMessages = array();
 
// English
$wgHierarchyMessages['en'] = array(
        $wgHierarchyPrefix . 'index' => "Index",
        $wgHierarchyPrefix . 'subordinates_separator' => "'''Sub-pages:'''",
);
 
// Portuguese - Português
$wgHierarchyMessages['pt'] = array(
        $wgHierarchyPrefix . 'index' => utf8_encode("Índice"),
        $wgHierarchyPrefix . 'subordinates_separator' => utf8_encode("'''Páginas subordinadas:'''"),
);
 
// Portuguese (Brazilian) - Português (brasileiro)
$wgHierarchyMessages['pt-br'] = array(
        $wgHierarchyPrefix . 'index' => utf8_encode("Índice"),
        $wgHierarchyPrefix . 'subordinates_separator' => utf8_encode("'''Páginas subordinadas:'''"),
);
 
// German - Deutsch
$wgHierarchyMessages['de'] = array(
        $wgHierarchyPrefix . 'index' => utf8_encode("Inhalt"),
        $wgHierarchyPrefix . 'subordinates_separator' => utf8_encode("'''Unterseiten:'''"),   
);
 
#----------------------------------------------------------------------------
#    Extension initialization
#----------------------------------------------------------------------------
 
// Credits
$wgExtensionCredits['parserhook'][] = array(
        'name'=>'Hierarchy',
        'version'=>$HierarchyVersion,
        'author'=>'Fernando Correia, Mark Hechim, Tom Boven',
        'url'=>'http://www.mediawiki.org/wiki/Extension:Hierarchy',
        'description' => 'Creates a hierarchical page navigation structure'
        );
 
// Register extension
//$wgExtensionFunctions[] = "wfHierarchyExtension";
$wgHooks['ParserFirstCallInit'][] = 'fnHierarchyExtension';
$wgHooks['ArticleSaveComplete'][] = 'fnHierarchySaveHook';
 
# Initialize extension
function wfHierarchyExtension() {
        // register the extension with the WikiText parser
        global $wgParser;
        $wgParser->setHook( "index", "renderHierarchyIndex" );
        // register messages
        global $wgMessageCache, $wgHierarchyMessages;
        foreach( $wgHierarchyMessages as $sLang => $aMsgs ) {
                $wgMessageCache->addMessages( $aMsgs, $sLang );
        }    
}
 
$wgHierarchyEmbedSubordinates = true;
$wgHierarchyNavigateSubordinates = true;
$wgHierarchyNavigationBoxBaseWidth = 140;
$wgHierarchyNavigationBoxIncrement = 19;

#----------------------------------------------------------------------------
#    Extension implementation
#----------------------------------------------------------------------------
 
# Processes a hierarchy index.
function renderHierarchyIndex( $input, $argv = array(), $parser ) {
        $hierarchy = new Hierarchy();
        $root_text = "";
        if( isset( $argv['root'] ) ) {
                $root_text = $argv['root'];
        }
        $deslash = 0;
        if( isset( $argv['deslash'] ) ) {
                $deslash = (int)$argv['deslash'];
        }
        $rval = $hierarchy->Render($input, $parser->mTitle, $parser->mOptions, $root_text, $deslash);
        $rval = str_replace("<span class=\"toctext\">$root_text", '<span class="toctext">', $rval);
        if( $deslash != 0 ) {
                $pattern = '@<span class="toctext">((?:[\w\s]*/)+)(.*?)</span>@';
                $rval = preg_replace($pattern, '<span class="toctext">$2</span>', $rval);
        }
        return $rval;
}
function fnHierarchyExtension( Parser &$parser ) {
        $parser->setHook( "index", "renderHierarchyIndex" );

        // register messages
        global $wgMessageCache, $wgHierarchyMessages;
        foreach( $wgHierarchyMessages as $sLang => $aMsgs ) {
                $wgMessageCache->addMessages( $aMsgs, $sLang );
        }    
	return true;
}
 
#function fnHierarchySaveHook(&$article, &$user, &$text, &$summary, &$minoredit, &$watchthis, &$sectionanchor, &$flags) {
function fnHierarchySaveHook(&$article, &$user, $text, $summary, $minoredit, $watchthis, $sectionanchor, $flags) {
        // search for <index> tag
        $localParser = new Parser();
        #$text = $matches[0][0];
 $localParser->extractTagsAndParams(array('index'), $text, $matches);
        #$tmp = '';
 while( $cur = current($matches) ) {
                $args = $cur[2];
                $root_text = '';
                if( isset( $args['root'] ) ){
                        $root_text = $args['root'];
                }
                $deslash = 0;
                if( isset( $args['deslash'] ) ){
                        $deslash = (int)$args['deslash'];
                }
                $index_text = $cur[1];
                $hierarchy = new Hierarchy();
                $hierarchy->Save($index_text, $article, $user, $root_text, $deslash);
 
                #$tmp = $tmp . "\nroot=" . $vals['root'] . "\n" . $cur[3] . "\n";
         next($matches);
        }
        return true;
}
 
#----------------------------------------------------------------------------
#    Hierarchy class for extension
#----------------------------------------------------------------------------
 
class Hierarchy {
 
        function Hierarchy() {
        }
 
        function Render($input, $title, $options, $root_text, $deslash) {
                // parse text
                $localParser = new Parser();
                $output = $localParser->parse($input, new Title(), $options);  //$title
                $html_text = $output->getText();
                $offset = 0;
 
                // find root page
                $pattern = '@<p>(<a href=.*?</a>).*?</p>@s';
                if (!preg_match($pattern, $html_text, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                        $pattern = '@\[\[(.*?)\|(.*?)\]\]@s';
                        $offset = 0;
                        if (!preg_match($pattern, $input, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                                //throw new Exception($input);
                                //return $html_text;
                                $matches[0] = array(0, 0);
                                $matches[1] = array('', 0);
                                // Just fake it
                        }
                }
                $root_page_link = $matches[1][0];
                $offset = $matches[0][1];
 
                // find TOC
                $pattern = '@<table id="toc"(.*?)</table>@s';
                if (!preg_match($pattern, $html_text, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                        return $html_text;
                }
                $toc = $matches[0][0];
                $offset = $matches[0][1];
 
                // change TOC title
                $pattern = '@(<div id="toctitle"><h2>)(.*?)(</h2></div>)@';
                $replacement = '$1' . $root_page_link . '$3';
                $toc = preg_replace ($pattern, $replacement, $toc, 1);
 
                // change TOC links
                $pattern = '@<li class="toclevel-(.*?)"><a href="(#.*?)"><span class="tocnumber">(.*?)</span> <span class="toctext">(.*?)</span></a>@';
                do {
                        $topic_found = preg_match($pattern, $toc, $matches, PREG_OFFSET_CAPTURE);
                        if ($topic_found) {
                                $item_text = $matches[4][0];
                                $title = Title::newFromText($item_text);
                                $page_url = $title->escapeLocalURL();
                                $url_position = $matches[2][1];
                                $url_length = strlen($matches[2][0]);
                                $toc = substr_replace($toc, $page_url, $url_position, $url_length);
                        }
                } while ($topic_found);
 
                // return HTML output
                return $toc;
        }
 
        function Save($text, $article, $user, $root_text, $deslash) {
                $article_id = $article->getID();
                if ($article_id) {  // Verify that the page has been saved at least once
                        $this->EraseInformation($article_id);
                        $parsed_text = $this->Render($text, $article->mTitle, new ParserOptions($user), $root_text, $deslash);
                        $this->SaveIndex($parsed_text, $article_id, $root_text, $deslash);
                }
        }
 
        // Erases information about this hierarchy in the database.
        function EraseInformation($index_article_id) {
                $fname = 'Hierarchy::EraseInformation';
                $dbw =& wfGetDB( DB_MASTER );
                $dbw->delete('hierarchy',
                        array(
                                'IndexArticleId' => $index_article_id,
                        ), $fname
                );
        }
 
        function SaveIndex($text, $index_article_id, $root_text, $deslash) {
                // get hierarchy root
                $offset = 0;
                $pattern = '@<table id="toc"(.*?)<a href=(.*?)</a></h2></div>@is';
                if (!preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                        $href = '';
                        $root_title = 'Main_Page';
                        $title = Title::newFromText($root_title);
                        $root_article_id = $title->getArticleID();
                        $parent_id[0] = $root_article_id;
                        //return;
                } else {
                        $href = $matches[2][0];
                        $offset = $matches[0][1];
                        $pattern = '@title="(.*?)">@';
                        if (!preg_match($pattern, $href, $matches, PREG_OFFSET_CAPTURE)) { return; }
                        $root_title = $matches[1][0];
                        $title = Title::newFromText($root_title);
                        $root_article_id = $title->getArticleID();
                        $parent_id[0] = $root_article_id;
                }
                $max_level = 0;
                $sequence = 0;
                // add root item to the database
                $hierarchyItem = new HierarchyItem();
                $hierarchyItem->mIndexArticleId = $index_article_id;
                $hierarchyItem->mTocLevel = 0;
                $hierarchyItem->mTocNumber = "";
                $hierarchyItem->mTocText = $root_title;
                $hierarchyItem->mSequence = $sequence++;
                $hierarchyItem->mArticleId = $root_article_id;
                $hierarchyItem->mPreviousArticleId = 0;
                $hierarchyItem->mNextArticleId = 0;
                $hierarchyItem->mParentArticleId = 0;
                $hierarchyItem->mRootText = $root_text;
                $hierarchyItem->mDeslash = (int)$deslash;
                $hierarchyItem->deleteArticleId();  // remove article from any other hierarchy
                $hierarchyItem->addToDatabase();  // add article to this hierarchy
                $previousHierarchyItem = $hierarchyItem;
                // process items
                while (true) {  // The function will return when a pattern match fails
                        // find TOC level as integer
                        $pattern = '@<li class=\"toclevel-(.*?)( tocsection-.*)?\">@';
                        if (!preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset)) { return; }
                        $toclevel = $matches[1][0];
                        $offset = $matches[0][1];
                        // find TOC number as string
                        $pattern = '@<span class=\"tocnumber\">(.*?)</span>@';
                        if (!preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset)) { return; }
                        $TOC_number = $matches[1][0];
                        $offset = $matches[0][1];
                        // find TOC text as Unicode string
                        $pattern = '@<span class=\"toctext\">(.*?)</span>@';
                        if (!preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset)) { return; }
                        $TOC_text = $matches[1][0];
                        $offset = $matches[0][1];
                        // article title
                        $title = Title::newFromText($TOC_text);
                        $current_article_id = $title->getArticleID();
                        // parent article
                        $parent_id[$toclevel] = $current_article_id;
                        if ($toclevel > $max_level) {
                                $max_level = $toclevel;
                        }
                        for ($i = $toclevel + 1; $i <= $max_level; $i++) {
                                $parent_id[$i] = 0;  // clear lower levels to prevent using an old value in case some intermediary levels are omitted
                        }
                        $parentArticleId = $parent_id[$toclevel - 1];
                        $parentArticleId = intval($parentArticleId);
                        // add item to the database
                        $hierarchyItem = new HierarchyItem();
                        $hierarchyItem->mIndexArticleId = $index_article_id;
                        $hierarchyItem->mTocLevel = $toclevel;
                        $hierarchyItem->mTocNumber = $TOC_number;
                        $hierarchyItem->mTocText = str_replace($root_text, "", $TOC_text);
                        $hierarchyItem->mSequence = $sequence++;
                        $hierarchyItem->mArticleId = $current_article_id;
                        $hierarchyItem->mPreviousArticleId = $previousHierarchyItem->mArticleId;
                        $hierarchyItem->mNextArticleId = 0;
                        $hierarchyItem->mParentArticleId = $parentArticleId;
                        $hierarchyItem->mRootText = $root_text;
                        $hierarchyItem->mDeslash = (int)$deslash;
                        $hierarchyItem->deleteArticleId();  // remove article from any other hierarchy
                        $hierarchyItem->addToDatabase();  // add article to this hierarchy
                        // update previous article
                        $previousHierarchyItem->mNextArticleId = $current_article_id;
                        $previousHierarchyItem->updateNextArticleId();
                        $previousHierarchyItem = $hierarchyItem;
                }
        }
}
 
#----------------------------------------------------------------------------
#    HierarchyItem class for extension
#----------------------------------------------------------------------------
 
class HierarchyItem {
        var $mId;
        var $mIndexArticleId;
        var $mTocLevel;
        var $mTocNumber;
        var $mTocText;
        var $mSequence;
        var $mArticleId;
        var $mPreviousArticleId;
        var $mNextArticleId;
        var $mParentArticleId;
        var $mRootText;
        var $mDeslash;
 
        function HierarchyItem() {
        }
 
        static function newFromArticleId($article_id) {
                $article_id = intval($article_id);
                $fname = 'HierarchyItem::newFromID';
                $dbr =& wfGetDB( DB_SLAVE );
                $row = $dbr->selectRow(
                        'hierarchy',
                        array(
                                        'Id',
                                        'IndexArticleId',
                                        'TocLevel',
                                        'TocNumber',
                                        'TocText',
                                        'Sequence',
                                        'ArticleId',
                                        'PreviousArticleId',
                                        'NextArticleId',
                                        'ParentArticleId',
                                        'RootText',
                                        'Deslash'
                        ),
                        array( 'ArticleId' => $article_id ),
                        $fname
                );
                if ( $row !== false ) {
                        $item = new HierarchyItem();
                        $item->mId = $row->Id;
                        $item->mIndexArticleId = $row->IndexArticleId;
                        $item->mTocLevel = $row->TocLevel;
                        $item->mTocNumber = $row->TocNumber;
                        $item->mTocText = $row->TocText;
                        $item->mSequence = $row->Sequence;
                        $item->mArticleId = $row->ArticleId;
                        $item->mPreviousArticleId = $row->PreviousArticleId;
                        $item->mNextArticleId = $row->NextArticleId;
                        $item->mParentArticleId = $row->ParentArticleId;
                        $item->mRootText = $row->RootText;
                        $item->mDeslash = $row->Deslash;
                } else {
                        $item = NULL;
                }
                return $item;
        }
 
        /**
        * Add object to the database
        */
        function addToDatabase() {
                $fname = 'HierarchyItem::addToDatabase';
                $dbw =& wfGetDB( DB_MASTER );
                $this->mId = $dbw->nextSequenceValue( 'HierarchyItem_id_seq' );
                $dbw->insert( 'hierarchy',
                        array(
                                        'Id' => $this->mId,
                                        'IndexArticleId' => $this->mIndexArticleId,
                                        'TocLevel' => $this->mTocLevel,
                                        'TocNumber' => $this->mTocNumber,
                                        'TocText' => $this->mTocText,
                                        'Sequence' => $this->mSequence,
                                        'ArticleId' => $this->mArticleId,
                                        'PreviousArticleId' => $this->mPreviousArticleId,
                                        'NextArticleId' => $this->mNextArticleId,
                                        'ParentArticleId' => $this->mParentArticleId,
                                        'RootText' => $this->mRootText,
                                        'Deslash' => $this->mDeslash,
                        ), $fname
                );
                $this->mId = $dbw->insertId();
        }
 
        /**
        * Update NextArticleId in the database
        */
        function updateNextArticleId() {
                $fname = 'HierarchyItem::updateNextArticleId';
                $dbw =& wfGetDB( DB_MASTER );
                $dbw->update( 'hierarchy',
                                        array( 'NextArticleId' => $this->mNextArticleId ),
                                        array( 'Id' => $this->mId ),
                                        $fname );
        }
 
        // Deletes any record with the current ArticleId from the database.
        function deleteArticleId() {
                $fname = 'HierarchyItem::delete';
                $dbw =& wfGetDB( DB_MASTER );
                $dbw->delete('hierarchy',
                        array(
                                        'ArticleId' => $this->mArticleId,
                        ), $fname
                );
        }
}
 
#----------------------------------------------------------------------------
#    Parser functions initialization
#----------------------------------------------------------------------------
 
// register parser functions
// $wgExtensionFunctions[] = 'wfHierarchyParserFunction_Setup';
$wgHooks['LanguageGetMagic'][] = 'wfHierarchyParserFunction_Magic';
$wgHooks['ParserFirstCallInit'][] = 'wfHierarchyParserFunction_Setup';
 
function wfHierarchyParserFunction_Setup(Parser &$parser) {
        # Set a function hook associating the magic word with our function
	$parser->setFunctionHook( 'hierarchy-top', 'wfHierarchyTopRender' );
        $parser->setFunctionHook( 'hierarchy-bottom', 'wfHierarchyBottomRender' );
 
        // Breadcrumb line added
        $parser->setFunctionHook( 'hierarchy-breadcrumb', 'wfHierarchyBreadcrumbRender' );

	return true;
}
 
function wfHierarchyParserFunction_Magic( &$magicWords, $langCode ) {
        # Add the magic word
 # The first array element is case sensitivity, in this case it is not case sensitive
 # All remaining elements are synonyms for our parser function
 $magicWords['hierarchy-top'] = array( 0, 'hierarchy-top' );
        $magicWords['hierarchy-bottom'] = array( 0, 'hierarchy-bottom' );
 
        // Breadcrumb line added
        $magicWords['hierarchy-breadcrumb'] = array( 0, 'hierarchy-breadcrumb' );
 
        # unless we return true, other parser functions extensions won't get loaded.
 return true;
}
 
function custRenderRecursiveChilds($article_id, $level, &$article_tree){
	$siblings = wfHierarchySubordinateArticles($article_id);
	foreach ($siblings as $sibling_id) {
		$sibling_article = HierarchyItem::newFromArticleId($sibling_id);
		if (!empty($sibling_article)) {
			$sibling_level = $level + 1;
			$max_level = $sibling_level;
			$article_tree[] = array('Level' => $sibling_level, 'Article' => $sibling_article);
			custRenderRecursiveChilds($sibling_id, $sibling_level, &$article_tree);
		}
	} 
}


#----------------------------------------------------------------------------
#    Parser functions implementation
#----------------------------------------------------------------------------
 
function wfHierarchyTopRender( Parser $parser ) {
        // get item
        $item = wfHierarchyGetItem($parser);
        if ($item == NULL) {
                return "";
        }
 
        // build parent tree
        $parent_tree = array();
        $parent_id = $item->mParentArticleId;  // start with parent of current item
        do {
                if (!empty($parent_id)) {
                        $parent = HierarchyItem::newFromArticleId($parent_id);
                        if (!empty($parent)) {
                                array_unshift($parent_tree, $parent);
                                $parent_id_new = $parent->mParentArticleId;  // go up on parent chain
                                if( $parent_id_new != $parent_id ) {
                                        $parent_id = $parent_id_new;
                                } else {
                                $parent_id = 0;
                                }
                        } else {
                        $parent_id = 0;
                        }
                }
        } while (!empty($parent_id));  // go all the way up to the top
        if (count($parent_tree) == 0) {
                $parent_tree[] = $item;  // item is the top hierarchy page
        }
 
        // convert parent tree to article tree
        $article_tree = array();
        $max_level = 0;
        foreach ($parent_tree as $article) {
                $level = count($article_tree);
                $max_level = $level;
                $article_tree[] = array('Level' => $level, 'Article' => $article);
        }
 
        // navigate to subordinates if global option set or if top hierarchy page
        global $wgHierarchyNavigateSubordinates;
        $navigate_subordinates = $wgHierarchyNavigateSubordinates || empty($item->mParentArticleId);

        // insert peer articles into article tree
        $bottom_parent = $parent_tree[count($parent_tree) - 1];
        if (!empty($bottom_parent) && !empty($bottom_parent->mArticleId)) {
                $peers = wfHierarchySubordinateArticles($bottom_parent->mArticleId);
                $level = count($article_tree);
                $max_level = $level;
		global $wgHierarchyOldStyle;
		if ($wgHierarchyOldStyle){
	                foreach ($peers as $article_id) {
        	                $article = HierarchyItem::newFromArticleId($article_id);
                	        if (!empty($article)) {
                        	        $article_tree[] = array('Level' => $level, 'Article' => $article);
                                	if (($article_id == $item->mArticleId) && $navigate_subordinates) { // show subordinates of current item?
	                                // insert sibling articles into article tree
        	                                $siblings = wfHierarchySubordinateArticles($article_id);
                	                        foreach ($siblings as $sibling_id) {
                        	                        $sibling_article = HierarchyItem::newFromArticleId($sibling_id);
                                	                if (!empty($sibling_article)) {
                                        	                $sibling_level = $level + 1;
                                                	        $max_level = $sibling_level;
	                                                        $article_tree[] = array('Level' => $sibling_level, 'Article' => $sibling_article);
        	                                        }
                	                        }                                 
                        	        }
	                        }
        	        }
		} else {
			custRenderRecursiveChilds(0, 0, &$article_tree);
		}
        }
 
        // get link to top hierarchy page
        $top_hierarchy_article_link = "";
        if (count($article_tree) > 0 && $article_tree[0]['Level'] == 0) {  // there is a top hierarchy page on the array
                $top_hierarchy_article_entry = array_shift($article_tree);
                $top_hierarchy_article = $top_hierarchy_article_entry['Article'];
                if (!empty($top_hierarchy_article) && !empty($top_hierarchy_article->mArticleId)) {
                        $top_hierarchy_article_link = wfHierarchyArticleLink($top_hierarchy_article->mArticleId, $top_hierarchy_article);
                }
        }
 
        // get link to index article
        if ($item->mIndexArticleId) {
                $msg = htmlspecialchars(wfMsg('hierarchy_index'));
                $index_article_link = wfHierarchyArticleLink($item->mIndexArticleId, $item, $msg);
        } else {
                $index_article_link = "";
        }
 
        // table start
        global $wgHierarchyNavigationBoxBaseWidth, $wgHierarchyNavigationBoxIncrement;
        $box_width = $wgHierarchyNavigationBoxBaseWidth + $wgHierarchyNavigationBoxIncrement * $max_level;
        $table_start = "{|style=\"padding: 0.2em; margin-left:15px; border: 1px solid #B8C7D9; background:#f5faff; text-align:center; font-size: 95%\" align=\"right\"\n"; # width={$box_width}

        // top hierarchy page row
        if (!empty($top_hierarchy_article_link)) {
                $top_row =
                        "|-\n" .
                        "|style=\"background: #cedff2; padding: 0.2em; white-space: nowrap;\" |'''$top_hierarchy_article_link'''\n";
        } else {
                $top_row = "";
        }
 
        // peer and sibling pages area
        $content_area = 
                "|-\n" .
                "|style=\"text-align:left; white-space: nowrap;\" |\n";
        foreach($article_tree as $article_entry) {
                $level = $article_entry['Level'];
                $article = $article_entry['Article'];
                if (!empty($article) && !empty($article->mArticleId)) {
                        $article_link = wfHierarchyArticleLink($article->mArticleId, $article);
                        if (!empty($article_link)) {
                                $content_area .= str_repeat("*", $level) . $article_link . "\n";
                        }
                }
        }
 
        // index page row
        if (!empty($index_article_link)) {
                $index_row =
                        "|-\n" .
                        "|<hr>\n" .
                        "$index_article_link\n";
        } else {
                $index_row = "";
        }
 
        // table end
        $table_end = "|}\n";
 
        // navigation box
        $navigation_box = $table_start . $top_row . $content_area . $index_row . $table_end;
        return $navigation_box;
}
 
function wfHierarchyBottomRender( Parser $parser ) {
        // get item
        $item = wfHierarchyGetItem($parser);
        if ($item == NULL) {
                return "";
        }
 
        // subordinate pages
        $embedded_subordinates = "";
        global $wgHierarchyEmbedSubordinates;
        if ($wgHierarchyEmbedSubordinates) {
                $subordinates = wfHierarchySubordinateArticles($item->mArticleId);
                if (count($subordinates) > 0) {
                $separator = wfMsg('hierarchy_subordinates_separator');
                if (!empty($separator)) $separator .= "\n";
                        $embedded_subordinates .= $separator; 
                        foreach ($subordinates as $subordinate) {
                                $link = wfHierarchyArticleLink($subordinate, $item);
                                if (!empty($link)) {
                                        $embedded_subordinates .= "* $link\n";
                                }
                        }
                }
        }
 
        // navigation links
        $previous_article = wfHierarchyArticleLink($item->mPreviousArticleId, $item);
        $next_article = wfHierarchyArticleLink($item->mNextArticleId, $item);
        $navigation = "";
        if ($previous_article) {
                global $wgHierarchyNavPrevious; 
                $navigation .= $wgHierarchyNavPrevious . ' ' . $previous_article;
        }
        if ($next_article) {
                if ($navigation) {
                        $navigation .= "&nbsp;|&nbsp;";
                }
                global $wgHierarchyNavNext; 
                $navigation .= $next_article . '&nbsp;' . $wgHierarchyNavNext;
        }
        if ($navigation) {
                #$navigation = "\n----\n" . $navigation . "\n";
 }
 
        // result
        $result = $embedded_subordinates . $navigation;
        return $result;
}
 
// Generte a breadcrumb path based on the hierarchy
function wfHierarchyBreadcrumbRender( Parser $parser ) {
        $item = wfHierarchyGetItem($parser);
        if( $item == NULL ) {
                return "";
        }
        $ancestors = array();
        $tempItem = $item;
        while ($tempItem->mParentArticleId) {
                $ancestors[] = $tempItem->mParentArticleId;
                $tempItem = HierarchyItem::newFromArticleId($tempItem->mParentArticleId);
        }
        $breadcrumb = str_replace( '|632beg|' . $item->mRootText, '', '|632beg|' . Title::newFromID($item->mArticleId) );
 
        if( $item->mDeslash ) {
                $breadcrumb = preg_replace('@((?:[\w\s]*/)+)(.*?)@', '$2', $breadcrumb);
        }
 
        if (count($ancestors) > 0) {
                foreach ($ancestors as $ancestor) {
                        $link = wfHierarchyArticleLink($ancestor, $item);
                        if (!empty($link)) {
                                $breadcrumb = "$link &gt; " . $breadcrumb;
                        }
                }
        }
        return "<small>$breadcrumb</small>";
}
 
function wfHierarchyGetItem( Parser $parser) {
        $title = $parser->mTitle;
        if ($title == NULL) {
                return NULL;
        }
        $article_id = intval($title->getArticleID());
        if (!$article_id) {
                return NULL;
        }
        $item = HierarchyItem::newFromArticleId($article_id);
        return $item;
}
 
// Returns an array with the IDs of the articles subordinated to $article_id.
function wfHierarchySubordinateArticles($article_id) {
        $article_id = intval($article_id);
        $fname = 'wfHierarchySubordinateArticles';
        $dbr =& wfGetDB( DB_SLAVE );
        $res = $dbr->select(
                'hierarchy',
                array( 'ArticleId', ),
                array( 'ParentArticleId' => $article_id ),
                $fname,
                array(
                        'ORDER BY'  => 'Sequence',
                )
        );
        $result = array();
        while( $s = $dbr->fetchObject( $res ) ) {
                $result[] = $s->ArticleId;
        }
        return $result;
}
 
function wfHierarchyArticleLink($article_id, $item, $description = '') {
        $root_text = $item->mRootText;
        $deslash = $item->mDeslash;
 
        if (empty($article_id)) {
                return "";
        }
        $title = Title::newFromID($article_id);
        if ($title == NULL) {
                return "";
        }
        if (!$title->exists()) {
                return "";
        }
        $article_title = $title->getPrefixedText();
        if ($article_title == NULL) {
                return "";
        }
        if ($description) {
                $description = "|$description";
        } else {
                $description = "|$article_title";
        }
        $description = str_replace( "|$root_text", "|", $description );
 
        if( $deslash ) {
                $pattern = '@((?:[\w\s]*/)+)(.*?)@';
                $description = preg_replace($pattern, '$2', $description);
        }
 
        return "[[" . $article_title . $description . "]]";
}