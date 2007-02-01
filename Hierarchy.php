<pre>
<?php
// Hierarchy WikiMedia extension.
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

$HierarchyVersion = '1.0.0';

#----------------------------------------------------------------------------
#    Internationalized messages
#----------------------------------------------------------------------------

$wgHierarchyPrefix = "hierarchy_";
$wgHierarchyMessages = array();

// English
$wgHierarchyMessages['en'] = array(
    $wgHierarchyPrefix . 'index' => "Index",    
);

// Portuguese
$wgHierarchyMessages['pt'] = array(
    $wgHierarchyPrefix . 'index' => utf8_encode("Índice"),
);

// Portuguese (Brazilian)
$wgHierarchyMessages['pt-br'] = array(
    $wgHierarchyPrefix . 'index' => utf8_encode("Índice"),
);

#----------------------------------------------------------------------------
#    Extension initialization
#----------------------------------------------------------------------------

// Credits
$wgExtensionCredits['parserhook'][] = array(
    'name'=>'Hierarchy',
    'version'=>$HierarchyVersion,
    'author'=>'Fernando Correia',
    'url'=>'http://www.mediawiki.org/wiki/Extension:Hierarchy',
    'description' => 'Creates a hierarchical page navigation structure'
    );

// Register extension
$wgExtensionFunctions[] = "wfHierarchyExtension";
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

#----------------------------------------------------------------------------
#    Extension implementation
#----------------------------------------------------------------------------

# Processes a hierarchy index.
function renderHierarchyIndex( $input, $argv, &$parser ) {
    $hierarchy = new Hierarchy();
    return $hierarchy->Render($input, $parser->mTitle, $parser->mOptions);
}

function fnHierarchySaveHook(&$article, &$user, &$text, &$summary, &$minoredit, &$watchthis, &$sectionanchor, &$flags) {
    // search for <index> tag
    $pattern = '@<index>(.*?)</index>@is';
    if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
        $index_text = $matches[1][0];
        $hierarchy = new Hierarchy();
        $hierarchy->Save($index_text, $article, $user);
    }
    return true;
}

#----------------------------------------------------------------------------
#    Hierarchy class for extension
#----------------------------------------------------------------------------

class Hierarchy {

    function Hierarchy() {
    }

    function Render($input, $title, $options) {
        // parse text
        $localParser = new Parser();
        $output = $localParser->parse($input, $title, $options);
        $html_text = $output->getText();
        $offset = 0;

        // find root page
        $pattern = '@<p>(<a href=.*?</a>).*?</p>@s';
        if (!preg_match($pattern, $html_text, $matches, PREG_OFFSET_CAPTURE, $offset)) return $html_text;
        $root_page_link = $matches[1][0];
        $offset = $matches[0][1];

        // find TOC
        $pattern = '@<table id="toc"(.*?)</table>@s';
        if (!preg_match($pattern, $html_text, $matches, PREG_OFFSET_CAPTURE, $offset)) return $html_text;
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

    function Save($text, $article, $user) {
        $article_id = $article->getID();
        if ($article_id) {  // Verify that the page has been saved at least once
            $this->EraseInformation($article_id);
            $parsed_text = $this->Render($text, $article->mTitle, new ParserOptions($user));
            $this->SaveIndex($parsed_text, $article_id);
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

    function SaveIndex($text, $index_article_id) {
        // get hierarchy root
        $offset = 0;
        $pattern = '@<table id="toc"(.*?)<a href=(.*?)</a></h2></div>@is';
        if (!preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset)) return;
        $href = $matches[2][0];
        $offset = $matches[0][1];
        $pattern = '@title="(.*?)">@';
        if (!preg_match($pattern, $href, $matches, PREG_OFFSET_CAPTURE)) return;
        $root_title = $matches[1][0];
        $title = Title::newFromText($root_title);
        $root_article_id = $title->getArticleID();
        $max_level = 0;
        $parent_id[0] = $root_article_id;
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
        $hierarchyItem->deleteArticleId();  // remove article from any other hierarchy
        $hierarchyItem->addToDatabase();  // add article to this hierarchy
        $previousHierarchyItem = $hierarchyItem;
        // process items
        while (true) {  // The function will return when a pattern match fails
            // find TOC level as integer
            $pattern = '@<li class=\"toclevel-(.*?)\">@';
            if (!preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset)) return;
            $toclevel = $matches[1][0];
            $offset = $matches[0][1];
            // find TOC number as string
            $pattern = '@<span class=\"tocnumber\">(.*?)</span>@';
            if (!preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset)) return;
            $TOC_number = $matches[1][0];
            $offset = $matches[0][1];
            // find TOC text as Unicode string
            $pattern = '@<span class=\"toctext\">(.*?)</span>@';
            if (!preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset)) return;
            $TOC_text = $matches[1][0];
            $offset = $matches[0][1];
            // article title
            $title = Title::newFromText($TOC_text);
            $current_article_id = $title->getArticleID();
            // parent article
            $parent_id[$toclevel] = $current_article_id;
            if ($toclevel > $max_level) $max_level = $toclevel;
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
            $hierarchyItem->mTocText = $TOC_text;
            $hierarchyItem->mSequence = $sequence++;
            $hierarchyItem->mArticleId = $current_article_id;
            $hierarchyItem->mPreviousArticleId = $previousHierarchyItem->mArticleId;
            $hierarchyItem->mNextArticleId = 0;
            $hierarchyItem->mParentArticleId = $parentArticleId;
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
                'ParentArticleId'
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
$wgExtensionFunctions[] = 'wfHierarchyParserFunction_Setup';
$wgHooks['LanguageGetMagic'][] = 'wfHierarchyParserFunction_Magic';

function wfHierarchyParserFunction_Setup() {
    global $wgParser;
    # Set a function hook associating the magic word with our function
    $wgParser->setFunctionHook( 'hierarchy-top', 'wfHierarchyTopRender' );
    $wgParser->setFunctionHook( 'hierarchy-bottom', 'wfHierarchyBottomRender' );
}

function wfHierarchyParserFunction_Magic( &$magicWords, $langCode ) {
    # Add the magic word
    # The first array element is case sensitivity, in this case it is not case sensitive
    # All remaining elements are synonyms for our parser function
    $magicWords['hierarchy-top'] = array( 0, 'hierarchy-top' );
    $magicWords['hierarchy-bottom'] = array( 0, 'hierarchy-bottom' );
    # unless we return true, other parser functions extensions won't get loaded.
    return true;
}

#----------------------------------------------------------------------------
#    Parser functions implementation
#----------------------------------------------------------------------------

function wfHierarchyTopRender( &$parser ) {
    // get item
    $item = wfHierarchyGetItem($parser);
    if ($item == NULL) return "";

    // index article
    if ($item->mIndexArticleId) {
    	$msg = htmlspecialchars(wfMsg('hierarchy_index'));
        $index_article = wfHierarchyArticleLink($item->mIndexArticleId, $msg);
    } else {
        $index_article = "";
    }

    // other articles
    if ($item->mParentArticleId) {  // has parent; show parent and siblings
        $parent_article = wfHierarchyArticleLink($item->mParentArticleId);
        $siblings = wfHierarchySubordinateArticles($item->mParentArticleId);
    } else {  // doesn't have parent; show item
        $parent_article = wfHierarchyArticleLink($item->mArticleId);
        $siblings = "";
    }

    // create hierarchy navigation box
    $result = "";
    if ($parent_article) {
        $result .=
            "{|style=\"padding: 0.2em; margin-left:15px; border: 1px solid #B8C7D9; background:#f5faff; text-align:center; font-size: 95%\" width=150px align=\"right\"\n" .
            "|-\n" .
            "|style=\"background: #cedff2; padding: 0.2em;\" |'''$parent_article'''\n";
        if ($siblings) $result .=
            "|-\n" .
            "|style=\"text-align:left;\" |\n" .
            "$siblings";
        if ($index_article) {
            $result .= "|-\n|";
            if ($siblings) $result .= "<hr>";
            $result .= "$index_article\n";
        }
        $result .=
            "|}\n";
    }

    return $result;
}

function wfHierarchyBottomRender( &$parser ) {
    // get item
    $item = wfHierarchyGetItem($parser);
    if ($item == NULL) return "";

    // subordinate pages
    $subordinate = wfHierarchySubordinateArticles($item->mArticleId);

    // navigation links
    $previous_article = wfHierarchyArticleLink($item->mPreviousArticleId);
    $next_article = wfHierarchyArticleLink($item->mNextArticleId);
    $navigation = "";
    if ($previous_article) {
        global $wgHierarchyNavPrevious; 
        $navigation .= $wgHierarchyNavPrevious . ' ' . $previous_article;
    }
    if ($next_article) {
        if ($navigation) $navigation .= " | ";
        global $wgHierarchyNavNext; 
        $navigation .= $next_article . ' ' . $wgHierarchyNavNext;
    }
    if ($navigation) $navigation = "\n\n----\n" . $navigation . "\n";

    // return wiki text
    return $subordinate . $navigation;
}

function wfHierarchyGetItem($parser) {
    $title = $parser->mTitle;
    if ($title == NULL) return NULL;
    $article_id = intval($title->getArticleID());
    if (!$article_id) return NULL;
    $item = HierarchyItem::newFromArticleId($article_id);
    return $item;
}

function wfHierarchySubordinateArticles($article_id) {
    $article_id = intval($article_id);
    $fname = 'wfHierarchySubordinateArticles';
    $dbr =& wfGetDB( DB_SLAVE );
    $res = $dbr->select(
        'hierarchy',
        array(
            'ArticleId',
        ),
        array( 'ParentArticleId' => $article_id ),
        $fname,
        array(
            'ORDER BY'  => 'Sequence',
        )
    );
    $result = "";
    while( $s = $dbr->fetchObject( $res ) ) {
        $link = wfHierarchyArticleLink($s->ArticleId);
        if ($link) $result .= "* " . $link . "\n";
    }
    return $result;
}

function wfHierarchyArticleLink($article_id, $description = '') {
    $title = Title::newFromID($article_id);
    if ($title == NULL) return "";
    if (!$title->exists()) return "";
    $article_title = $title->getPrefixedText();
    if ($article_title == NULL) return "";
    if ($description) $description = "|$description";
    return "[[" . $article_title . $description . "]]";
}

?>
</pre>