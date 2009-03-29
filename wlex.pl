#!/usr/bin/perl

# Copyright (C) 2002-2009 Peeter P. Mõtsküla <peeterpaul@motskula.net>
#
# This program is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or (at
# your option) any later version.
#
# This program is distributed in the hope that it will be useful, but
# WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
# See the GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License along
# with this program; if not, write to the Free Software Foundation, Inc.,
# 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA


# wlex.pl
#
# command-line usage:
#     wlex.pl
#         formats stdin
#     wlex.pl file|URI
#         formats the input file or URI
#     wlex.pl file1|URI1 file2|URI2
#         creates and formats the diff of first and second arguments
# CGI usage:
#     wlex.pl
#         displays the front page
#     wlex.pl?act=URI
#         formats the act
#     wlex.pl?act=ABBR
#         finds and formats the act with official abbreviation ABBR as of today
#     wlex.pl?act=TITLE[&now=DD.MM.YYYY]
#         finds and formats the act as of the date given (today by default)
#     wlex.pl?act=TITLE&old=DD.MM.YYYY&new=DD.MM.YYYY
#         creates and formats the diff of the act as of d1 (old) and d2 (new)
#
# input must conform to the format of "akt vorminduseta" in www.riigiteataja.ee
# dependencies: tidy, diff, wget

use strict;
use URI::Escape;
use POSIX;

### global "constants"
my $progID = 'wLex 3.1';
my $copyright = '&copy; 2002-2009 Peeter P. Mõtsküla, <a href="http://peeterpaul.motskula.net/">http://peeterpaul.motskula.net/</a>';
my $eRTver = "1.0.6 build 7"; # expected version of eRT
my $NUMA	= "[0-9]+";
my $NUMR	= "[IVXLCDM]+";
my $NSUP	= "(?:<sup>$NUMA</sup>)";
my $SNUM	= "(?:$NUMA$NSUP?|$NUMR$NSUP?)";
my $BR		= "(?:<br(?: /)?>)";
my $PBR		= "(?:$BR|</p>)";
my $SSEP	= "(?:\. ?)?(?:\. |$BR\n|(?:</p>\n<p>))";
my $PARA	= "(?:§ ?|Paragrahv )";


### default config parameters
my $wlexURI = ''; # must be defined in config.pl for CGI use
my $wlexTMP = 'wlex-'; # can be overridden in config.pl
my $wlexTracker = ''; # may be provided in config.pl
my %acts = ''; # should be provided in abbr.pl
eval `cat config.pl`;


### main block
my %args = &getArgs;
if ($args{'execMethod'} eq 'CLI') {
    # executed via CLI with at least one parameter
    if ($args{'act'}) {
        # format the act
        print &parse(&getAct($args{'act'}));
    } elsif ($args{'old'} && $args{'new'}) {
        # create a diff
        print &diff(&parse(&getAct($args{'old'})), &parse(&getAct($args{'new'})));
    } else {
        # invalid arguments
        &err("Invalid arguments.");
    }
} elsif ($args{'execMethod'} eq 'CGI') {
    # executed via CGI
    print "Content-type: text/html\n\n";
    if (! $wlexURI) {&err("Couldn't read config.pl.")}
    if ($args{'cat'}) {
        print &wrap(&catSection($args{'cat'}));
    } elsif ($args{'find'}) {
        # find given: display manual search page
        print &wrap(&findPage($args{'find'}));
    } elsif ($args{'act'}) {
        # act given: diff or format
        if ($args{'old'} && $args{'new'}) {
            # old and new dates given: diff
            print &wrap(&addToc(&diff(&parse(&findAct($args{'act'}, $args{'old'})), &parse(&findAct($args{'act'}, $args{'new'})))));
        } else {
            # format the act
            print &wrap(&addToc(&parse(&findAct($args{'act'}, &now))));
        }
    } else {
        # no parameters: display front page
        print &wrap(&frontPage);
    }
} else {
    # cannot determine execution method
    &err("Unknown execution method.");
}
### end of main block


sub err($) {
# display error message and quit
    local $_ = shift;
    print "ERROR: $_";
    if ($args{'execMethod'} eq 'CGI') { print "<br />" }
    print "\n";
    exit;
}


sub wget($) {
# wget a webpage
    local $_ = shift;
    return `wget -o /dev/null -O - '$_'` || &err("Cannot wget '$_'.");
}


sub now() {
# return $args{'now'} or current date as DD.MM.YYYY
    my $date;
    if ($date = $args{'now'}) {
        $date =~ m#^($NUMA)\.($NUMA)\.($NUMA)$#;
        unless (mktime(0, 0, 0, $1, $2-1, $3-1900)) { # invalid time value
            $args{'now'} = '';
            $date = &now;
        }
    } else {
        my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst) = localtime(time);
        $year += 1900;
        $mon++;
        $date = "$mday.$mon.$year";
    }
    return $date;
}


sub getArgs() {
# get %args from CLI or CGI arguments
    my %args;
    if ($#ARGV >= 0) {
        $args{'execMethod'} = 'CLI';
        if ($#ARGV == 0) {
            $args{'act'} = $ARGV[0];
        } elsif ($#ARGV == 1) {
            $args{'old'} = $ARGV[0];
            $args{'new'} = $ARGV[1];
        }
    } elsif (($ENV{'REQUEST_METHOD'} eq "GET") || ($ENV{'REQUEST_METHOD'} eq "POST")) {
        $args{'execMethod'} = 'CGI';
        my ($cgiData, %formData) = &getCGI;
        $args{'act'} = $formData{'act'};
        $args{'now'} = $formData{'now'};
        $args{'old'} = $formData{'old'};
        $args{'new'} = $formData{'new'};
        $args{'find'} = $formData{'find'};
        $args{'cat'} = $formData{'cat'};
    }
    return %args;
}

sub getCGI() {
# get $cgi_data and %form_data from CGI arguments
    my ($cgi_data, %form_data);
    if ($ENV{'REQUEST_METHOD'} eq 'GET') {
        $cgi_data = $ENV{'QUERY_STRING'};
    } else {
        my $data_length = $ENV{'CONTENT_LENGTH'};
        my $bytes_read = read (STDIN, $cgi_data, $data_length);
    }
    my @name_value_array = split(/&/, $cgi_data);
    foreach my $name_value_pair (@name_value_array) {
        my ($name, $value) = split(/=/, $name_value_pair);
        $name =~ tr/+/ /; $name =~ s/%(..)/pack("C",hex($1))/eg;
        $value =~ tr/+/ /; $value =~ s/%(..)/pack("C",hex($1))/eg;
        if ($form_data{$name}) {
            $form_data{$name} .= "\t$value";
        } else {
            $form_data{$name} = $value;
        }
    }
    return ($cgi_data, %form_data);
}


sub getAct($) {
# get act text from source file or URI given in argument
# if argument begins with https?://, use wget, otherwise file

    # get source file name or URI from argument
    local $_ = my $src = shift;
    if (m#^https?://#) {
        # input looks like URI
        $_ = uri_unescape($_);
        s#&amp;#&#g;
        
        # remove paragraph references from backlinks to superior acts in eRT
        if (/!/) {
            s#(.*?id=$NUMA)!.*?((?:&.*)|$)#$1$2#;
        }
        
        if (m#id=$NUMA;$NUMA#) { # multiple links given, display manual find
            print &wrap(&findPage($_));
            exit;
        }
        s#https://www\.riigiteataja\.ee/ert/act\.jsp\?#https://www.riigiteataja.ee/ert/ert.jsp?link=print&akt_vorminduseta=1&#; # redirect to "akt vorminduseta"
        open INPUT, "wget -o /dev/null -O - '$_' |" || &err("Cannot wget '$_'.");
    } else {
        # input must be a file
        open INPUT, $_ || &err("Cannot read '$_'.");
    }

    # read input file or URL
    local $/;
    my $act = <INPUT>;
    close INPUT;

    # extract <body> content
    $act =~ s#^.*?<body.*?>(.*?)</body>.*$#$1#s;

    # add link to source and formatter notice if source was URI
    if (m#^https?://#) {
        $act = qq#<p>wLex:src=$src</p>
$act#;
    }

    return $act;
}


sub findAct($;$) {
# get act text from URI or ((abbreviation or title) and date) given in argument
    local $_;
    my $act = shift;
    if ($act =~ m#^https?://#) {
        # act looks like URI, don't go searching
        $_ = &getAct($act);
    } else {
        eval `cat abbr.pl`;
        if ($_ = $acts{$act}) { # try finding act name from abbreviation
            $act = $_;
        }
        my $now = shift;
        $now =~ m#^($NUMA)\.($NUMA)\.($NUMA)$#;
        my $searchThis = my $searchERT = "/ert/ert.jsp?link=searchRes&date_day=$1&date_month=$2&date_year=$3&title=$act";
        my $pageNumber = 0;
        while ($searchThis) {
            $_ = &wget("https://www.riigiteataja.ee$searchThis"); # get next page of search results from eRT
            my $xget = $_; $xget =~ tr/ÕÄÖÜŠŽ/õäöüšž/; # the xget/xact hack is necessary to make accented characters case-insensitive in the following title search
            my $xact = $act; $xact =~ tr/ÕÄÖÜŠŽ/õäöüšž/;
            if ($xget =~ m#.*?<a href="(/ert/act.jsp\?id=[^"]*)">(?:<font [^>]*>)?(?:<u>)?$xact(?:¹)?(?:</u>)?(?:</font>)?</a>#si) { # exact title found
                $_ = &getAct("https://www.riigiteataja.ee$1");
                $searchThis = '';
            } elsif (($pageNumber++ < 3) && (m#<a href="($searchERT&numberLink=$NUMA)"><img src="gfx/dot3.gif" .*?></a>#si)) { # any more pages available? (search up to 3 pages only)
                $searchThis = $1;
            } else { # no more results available, exact title not found, revert to manual search
                print &wrap(&findPage("https://www.riigiteataja.ee$searchERT"));
                exit;
            }
        }
    }
    return $_;
}


sub findPage($) {
# prepare to display manual search page
    local $_ = shift;
    $_ = &wget($_);
    my $now = &now;
    my $link;
    my $toc = qq#<div class="tocexploder">\n<div id="toc">\n#;
    s#.*?Soovitud aktid.*?<font .*?>(.*?)</font>##;
    $toc .= qq#<p class="ttl">Leitud aktid: $1</p>\n#;
    $toc .= qq#<p class="osa">#;
    # first page
    s#.*?(?:<a href="([^"]*?)">)?<img src="gfx/dot1.gif" .*?>##;
    if ($link = $1) {
        $link = "$wlexURI?find=https://www.riigiteataja.ee".uri_escape($link)."&act=$args{'act'}&now=$now";
        $toc .= qq#<a href="$link">&lt;&lt;</a> #;
    } else {
        $toc .= "&lt;&lt; ";
    }
    # previous page
    s#.*?(?:<a href="([^"]*)">)?<img src="gfx/dot2.gif" .*?>##;
    if ($link = $1) {
        $link = "$wlexURI?find=https://www.riigiteataja.ee".uri_escape($link)."&act=$args{'act'}&now=$now";
        $toc .= qq#<a href="$link">&lt;</a> #;
    } else {
        $toc .= "&lt; ";
    }
    # next page
    s#.*?(?:<a href="([^"]*)">)?<img src="gfx/dot3.gif" .*?>##;
    if ($link = $1) {
        $link = "$wlexURI?find=https://www.riigiteataja.ee".uri_escape($link)."&act=$args{'act'}&now=$now";
        $toc .= qq#<a href="$link">&gt;</a> #;
    } else {
        $toc .= "&gt; ";
    }
    # last page
    s#.*?(?:<a href="([^"]*)">)?<img src="gfx/dot4.gif" .*?>##;
    if ($link = $1) {
        $link = "$wlexURI?find=https://www.riigiteataja.ee".uri_escape($link)."&act=$args{'act'}&now=$now";
        $toc .= qq#<a href="$link">&gt;&gt;</a> #;
    } else {
        $toc .= "&gt;&gt; ";
    }
    $toc .= "</p>\n";
    # close nav (toc)
    $toc .= "</div> <!-- /toc -->\n</div> <!-- /tocexploder -->";
    # list of acts
    my $page = qq#<div class="txtexploder">\n<div id="txt">\n<ul>\n#;
    while (s#.*?<a href="(/ert/act.jsp\?id=[^"]*)">(?:<font [^>]*>)?(?:<u>)?(.*?(?:¹)?)(?:</u>)?(?:</font>)?</a>##si) {
        $link = "$wlexURI?act=https://www.riigiteataja.ee".uri_escape($1)."&now=$now";
        $page .= qq#<li class="x"><a href="$link">$2</a></p>\n#;
    }
    # close <div id="txt">
    $page .= qq#</ul>\n</div> <!-- /txt -->\n</div> <!-- /txtexploder -->#;
    $_ = qq#<div id="act">\n$page$toc</div> <!-- /act -->\n#;
    return $_;
}


sub parse($) {
# format the html provided in parameter
    local $_ = shift;
    $_= qq#<html><body>$_</body></html>#;

    # pre-clean weird garbage
    s#</?fontsize.*?>##gsi;

    # pass input through tidy
    $_ = &tfpipe("tidy -f /dev/null -wrap 0 -utf8 -bc -asxml -", $_);

    # get <body> content only
    s#^.*<body.*?>\n(.*)</body>.*$#$1#s;

    # extract source URI if given
    s#<p>wLex:src=(.*?)</p>\n##;
    my $src = $1;

    # remove useless formatting & fill-ins
    s#<img.*?<br />\n<br />##si;		# kill top-of-page spacer
    s#\s +# #gsi;				# multiple spaces to single space
    s# ($NSUP)#$1#gsi;			# remove spaces before superscripted numbers
    s#§ -#§-#gsi;				# remove spaces between § and -
    s#</?(?:b|i|strong|em)>##gsi;		# kill <b>, <i>, <strong>, <em> and their closing tags
    s#<p.*?>#<p>#gsi;			# fancy <p> to plain <p>
    s#<p> *$BR* *</p>##gsi;		# kill empty <p>...</p>
    ##### s#$BR\n?#</p>\n<p>#gsi;		# convert <br /> to </p>\n<p>
    s#\n *\n#\n#gsi;			# kill empty lines
    s#($NUMA)\. +($NUMA)#$1.$2#gs;	# spaced dates to nonspaced dates
    s#</?span.*?>##gsi;			# kill <span> and </span>
    s#</?div.*?>##gsi;			# kill <div> and </div>
    
    # complete eRT links or convert to wLex links
    if ($wlexURI) {
        s#<a href="(act.jsp[^"]*)#qq[<a href="$wlexURI?act=https://www.riigiteataja.ee/ert/].uri_escape($1)#gsei;
    } else {
        s#<a href="act.jsp#<a href="https://www.riigiteataja.ee/ert/act.jsp#gsi;
    }
    s#<img src="get-attachment.jsp#<img src="https://www.riigiteataja.ee/ert/get-attachment.jsp#gs;

    # get act title
    s#\n<p>(.*?)( ?$NSUP?)</p>#\n<p class="ttl">$1$2</p>#i; ##### maybe use $PBR here to catch weird titles
    my $title=$1;

    # format special paragraphs
    # osa-ptk-jgu-jts-ajt standard format: (I|1.) type(. |\n)title
    s#\n<p>($SNUM\.? )(osa)$SSEP(.*?)</p>#\n<p class="osa">$1$2. $3</p>#gi;
    s#\n<p>($SNUM\.? )(peat(?:ü|Ü)kk)$SSEP(.*?)</p>#\n<p class="ptk">$1$2. $3</p>#gi;
    s#\n<p>($SNUM\.? )(jagu)$SSEP(.*?)</p>#\n<p class="jgu">$1$2. $3</p>#gi;
    s#\n<p>($SNUM\.? )(jaotis)$SSEP(.*?)</p>#\n<p class="jts">$1$2. $3</p>#gi;
    s#\n<p>($SNUM\.? )(alljaotis)$SSEP(.*?)</p>#\n<p class="ajt">$1$2. $3</p>#gi;

    # osa special format: I title
    s#\n<p>($NUMR$NSUP? .*?)</p>#\n<p class="osa">$1</p>#gi;

    # osa special format: ÜLDOSA | ERIOSA
    s#\n<p>((?:Ü|ü)ldosa|eriosa)</p>#\n<p class="osa">$1</p>#gi;

    # jgu special format: 1. title
    s#\n<p>($NUMA$NSUP?\. .*?)</p>#\n<p class="jgu">$1</p>#gi;

    # pg: PARA 1. title
    s#\n<p>($PARA)($SNUM)(.*?)#\n<p class="pg">§ $2$3#gi;

    # pg: PARA-d 1-2 title
    s#\n<p>($PARA[-i]d)($SNUM-$SNUM)(.*?)#\n<p class="pg">§-d $2$3#gi;

    # x: enactment notices
    {
        m#<p class="ttl">.*?</p>\n(<p>.*?</p>\n)<p class#s;
        my $x = $1;
        $x =~ s#<p>#<p class="x">#sg;
        s#(<p class="ttl">.*?</p>\n)(<p>.*?</p>\n)(<p class)#$1$x$3#s;
    }

    # add links to view old versions and diffs
    {
        my $now = &now;
        my $ttl = uri_escape($title);
        s#(<p class="x">.*?\) )($NUMA\.$NUMA\.$NUMA)(.*?)(</p>)#$1$2$3 (<a href="$wlexURI?act=$ttl&amp;now=$2">vaata</a> | <a href="$wlexURI?act=$ttl&amp;new=$now&amp;old=$2">võrdle</a>)$4#gs;
    }
    
    # rem: inlined comments []
    s#(\[.*?\])#<span class="rem">$1</span>#gs;

    # add attribution notice and source reference if given
    if ($src) {
        $_ = qq#<p class="rem">Ametlik tekst: <a href="$src">$src</a><br />\nVormindus: $progID $copyright</p>$_#;
    } else {
        $_ = qq#<p class="rem">Vormindus: $progID $copyright</p>$_#;
    }

    # add top anchor
    $_ = qq#<a name="top"></a>\n$_#;

    return $_;
}


sub wrap($) {
# add wlex pagewrapper & searchbar to the body content provided, using optional page title
    local $_ = shift;
    my $title;
    if (m#<p class="ttl">(.*?) ?$NSUP?</p>#s) {
        $title = $1;
    }
    my $act = $args{'act'};
    $act =~ s#https?://.*#$title#;
    $title = "wLex: $title" unless ($title =~ /^wLex: /);
    my $now = &now;
    s#<div id="act">#<div id="act">
<div id="nav">
<form action="$wlexURI" method="get"><a href="$wlexURI">wLex</a> | otsing : <input type="text" name="act" size="30" value="$act" /> seisuga <input type="text" name="now" size="10" value="$now" /> <input type="submit" name="search" value="pealkirjadest" /><input type="submit" name="search" value="tekstidest" /></form>
</div> <!-- /nav -->#;
    $_ = qq#<!-- quirksmode -->
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="generator" content="$progID" />
    <title>$title</title>
    <link rel="stylesheet" type="text/css" href="style.css" />
    <!--[if lte IE 7]>
    <link rel="stylesheet" type="text/css" href="ie.css" />
    <script type="text/javascript">onload = function() { txt.focus() }</script>
    <![endif]-->
</head>
<body>
$_
$wlexTracker
</body>
</html>
#;
    return $_;
}


sub tfpipe($$) {
    # usage: &tfpipe($cmd, $data)
    # ! $cmd must contain a free-standing '-'
    my ($cmd, $data, $tmp);
    while (1) { # setup temp file
        $tmp = rand;
        $tmp =~ s/0./$wlexTMP/; # ! uses global $TMP
        last unless (-e $tmp); # try again if $tmp exists;
    }
    $cmd = shift; # get command
    $cmd =~ s/(\s)(-)(\s|\z)/$1$tmp$3/ || &err("No '-' in command '$cmd'.");
    $data = shift; # get data
    open TMP, ">$tmp" || &err("Cannot open temporary file '$tmp' for writing.");
    print TMP $data;
    close TMP;
    local $_ = `$cmd`;
    unlink $tmp;
    return $_;
}


sub addToc($) {
    # create table of contents
    # usage: &addToc($parsedAct)
    local $_ = shift || die "ERROR: nothing to add TOC to.\n";

    split /\n/;
    my $toc;
    LINE: for $_ (@_) {
        if (m#^<p class="ttl">(.*? ?$NSUP?)</p>#) {
            $toc .= qq#<p class="ttl"><a href="\#top">$1</a></p>\n#;
        }
        if (m#^(<p class="pg">)(<ins>|<del>)?§ ($SNUM)(.*)(</ins>|</del>)?</p>#) {
            my $para = $1;
            my $tagA = $2;
            my $pnum = $3;
            my $text = $4;
            my $tagZ = $5;
            my $nx = $pnum;
            $nx =~ s#<sup>#.#;
            $nx =~ s#</sup>##;
            if ($tagA eq "<del>") { # special anchors for deleted paragraphs (in diff)
                $nx .= "x";
            }
            $_ = "<a name=\"p$nx\"></a>$para$tagA§ $pnum$text$tagZ</p>";
            $text =~ s#<a .*?>##g;
            $text =~ s#</a>##g;
            $toc .= "$para<a href=\"#p$nx\">$tagA§ $pnum$text$tagZ</a></p>\n";
            next LINE;
        }
        if (m#^(<p class=")(osa|ptk|jgu|jts|ajt)(".*)#) {
            ##$toc .= "$1x$2$3\n";
            $toc .= "$_\n";
            next LINE;
        }
    }
    $_ = join "\n", (@_);
    $_ = qq#<div id="act">
<div class="txtexploder">
<div id="txt">
$_
</div> <!-- /txt -->
</div> <!-- /txtexploder -->
<div class="tocexploder">
<div id="toc">
$toc</div> <!-- /toc -->
</div> <!-- /tocexploder -->
</div> <!-- /act -->#;

    return $_;
}


sub frontPage() {
# show systematic catalog in TOC and welcome message in TXT
    my $toc = &sysCat;
    my $txt = qq#<div class="txtexploder">\n<div id="txt">\n#.`cat cover.htm`.qq#</div> <!-- /txt -->\n</div> <!-- /txtexploder -->#;
    $txt =~ s#\$progID#$progID#gs;
    return qq#<div id="act">\n$txt$toc</div> <!-- /act -->#;
}


sub sysCat() {
# get systematic catalog from eRT
    my $toc = qq#<div class="tocexploder">\n<div id="toc">\n#;
    local $_ = &wget('https://www.riigiteataja.ee/ert/ert.jsp?link=jaotusyksused-form');
    while (s#.*?<td class="jaotusyksus".[^>]*>(.*?)</td>##is) {
        my $line = $1;
        if ($line =~ m#<b>(.*?)</b>#) { # section title
            $toc .= qq#<p class="osa">$1</p>\n#;
        } elsif ($line =~ m#<a href="(ert.jsp\?link=jaotusyksuse-aktid&jaotusyksused=.*?)">(.*?) *</a>#) { # subsection link
            my $link = $1;
            my $title = $2;
            $link = qq#$wlexURI?cat=https://www.riigiteataja.ee/ert/#.uri_escape($link);
            $toc .= qq#<p class="pg"><a href="$link">$title</a></p>\n#;
        }
    }
    $toc .= qq#</div> <!-- /toc -->\n</div> <!-- /tocexploder -->#;
    return $toc;
}


sub catSection($) {
# show selected section of eRT's systematic catalog
    my $toc = &sysCat;
    my $txt = qq#<div class="txtexploder">\n<div id="txt">\n<ul>\n#;
    local $_ = &wget(shift);
    s#.*?class="pealkiri1"><b>(.*?) > (.*?) : ##;
    $txt .= qq#<p class="ttl">$1 &gt; $2</p>\n#;
    # list of acts
    while (s#.*?<a href="(/ert/act.jsp\?id=[^"]*)">(?:<font [^>]*>)?(?:<u>)?(.*?(?:¹)?)(?:</u>)?(?:</font>)?</a>##si) {
        my $link = "$wlexURI?act=https://www.riigiteataja.ee".uri_escape($1);
        $txt .= qq#<li class="x"><a href="$link">$2</a></p>\n#;
    }
    $txt .= qq#</ul>\n</div> <!-- /txt -->\n</div> <!-- /txtexploder -->\n#;
    $_ = qq#<div id="act">\n$txt$toc</div> <!-- /act -->#;
}

sub diff($$) {
# make a diff between two documents

    my $old = shift;
    my $new = shift;
    my ($tfa, $tfb, $diff);
    
    # setup temp files
    while (1) {
        $tfa = rand;
        $tfa =~ s/0./$wlexTMP/; # ! uses global $TMP
        last unless (-e $tfa); # try again if file exists;
    }
    open TMP, ">$tfa" || &err("Cannot open temporary file '$tfa' for writing.");
    print TMP $old;
    close TMP;
    while (1) {
        $tfb = rand;
        $tfb =~ s/0./$wlexTMP/; # ! uses global $TMP
        last unless (-e $tfb); # try again if file exists;
    }
    open TMP, ">$tfb" || &err("Cannot open temporary file '$tfb' for writing.");
    print TMP $new;
    close TMP;

    # create diff
    $diff = `diff -iwU -1 $tfa $tfb`;

    # remove temp files
    unlink $tfa;
    unlink $tfb;

    # cleanup and format diff
    $diff =~ s#.*<a name="top">#<a name="top">#s;
    $diff =~ s# (<p(?: .*?)?>)(.*?)(</p>)#$1$2$3#gs;
    $diff =~ s#\-(<p(?: .*?)?>)(.*?)(</p>)#$1<del>$2</del>$3#gs;
    $diff =~ s#\+(<p(?: .*?)?>)(.*?)(</p>)#$1<ins>$2</ins>$3#gs;

    return $diff;
}
