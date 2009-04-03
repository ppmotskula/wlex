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

# wlex.pl usage
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
# dependencies: tidy, diff, wget, URI::Escape, Mail::Sendmail

use strict;
use POSIX;
use URI::Escape;
use Mail::Sendmail;

### global "constants"
my $progID  = 'wLex 3.1';
my $copyright = '&copy; 2002-2009 <a href="http://peeterpaul.motskula.net/">Peeter P. Mõtsküla</a>';
my $eRTver  = "1.1.4 build 1"; # expected version of eRT
my $NUMA	= "[0-9]+";
my $NUMR	= "[IVXLCDM]+";
my $NSUP	= "(?:<sup>$NUMA</sup>)";
my $SNUM	= "(?:$NUMA$NSUP?|$NUMR$NSUP?)";
my $BR		= "(?:<br(?: /)?>)";
my $PBR		= "(?:$BR|</p>)";
my $SSEP	= "(?:\. ?)?(?:\. |$BR\n|(?:</p>\n<p>))";
my $PARA	= "(?:§ ?|Paragrahv )";
my $TZDIFF  = 7200; # GMT+2 for Estonia

### default config parameters and global variables
my $wlexURI     = '';       # must be defined in config.pl for CGI use
my $wlexTMP     = 'wlex-';  # can be overridden in config.pl
my $wlexTracker = '';       # may be provided in config.pl
my $bugFROM     = 'peeterpaul@motskula.net'; # bug reports sent from
my $bugTO       = 'ticket+wolli.28256-zm4h6kpq@lighthouseapp.com'; # bug reports sent to
my $bugURI      = 'http://wolli.lighthouseapp.com/projects/28256-wlex/home'; # bug tracker
my %Acts;                   # should be provided in abbr.pl
my %Args;                   # CLI or CGI argument list
eval `cat config.pl`;

main();

sub main() {
    %Args = get_args();
    if ($Args{'exec_method'} eq 'CLI') {
        # executed via CLI with at least one parameter
        if ($Args{'act'}) {
            # format the act
            print parse(get_act($Args{'act'}));
        } elsif ($Args{'old'} && $Args{'new'}) {
            # create a diff
            print diff(parse(get_act($Args{'old'})),
                       parse(get_act($Args{'new'})));
        } else {
            # invalid arguments
            err("Invalid arguments.");
        }
    } elsif ($Args{'exec_method'} eq 'CGI') {
        # executed via CGI
        print "Content-type: text/html\n\n";
        if (! $wlexURI) {
            err("Couldn't read config.pl.");
            exit;
        }
        if ($Args{'bug'} eq "form") {
            print wrap(bug_form());
        } elsif ($Args{'bug'} eq "send") {
            print wrap(bug_send());
        } elsif ($Args{'cat'}) {
            print wrap(cat_section($Args{'cat'}));
        } elsif ($Args{'find'}) {
            # find given: display manual search page
            print wrap(find_page($Args{'find'}));
        } elsif ($Args{'act'}) {
            # act given: diff or format
            if ($Args{'old'} && $Args{'new'}) {
                # old and new dates given: diff
                print wrap(add_toc(diff(
                                    parse(find_act($Args{'act'}, $Args{'old'})),
                                    parse(find_act($Args{'act'}, $Args{'new'}))
                                    )));
            } else {
                # format the act
                print wrap(add_toc(parse(find_act($Args{'act'}, now()))));
            }
        } else {
            # no parameters: display front page
            print wrap(front_page());
        }
    } else {
        # cannot determine execution method
        err("Unknown execution method.");
    }
}

sub err($) {
# display an error message
# note: err doesn't call die() to allow proper closing of HTML tags
    local $_ = shift;
    print "ERROR: $_";
    if ($Args{'exec_method'} eq 'CGI') { print "<br />" }
    print "\n";
    exit;
}

sub wget($) {
# wget a webpage
    local $_ = shift;
    return `wget -o /dev/null -O - '$_'` || err("Cannot wget '$_'.");
}

sub now() {
# return $Args{'now'} or current date as DD.MM.YYYY
    my $date;
    if ($date = $Args{'now'}) {
        $date =~ m#^($NUMA)\.($NUMA)\.($NUMA)$#;
        unless (mktime(0, 0, 0, $1, $2-1, $3-1900)) { # invalid time value
            $Args{'now'} = '';
            $date = now();
        }
    } else {
        my ($sec, $min, $hour, $mday, $mon, $year, $wday, $yday, $isdst) =
           gmtime(time + $TZDIFF);
        $year += 1900;
        $mon++;
        $date = "$mday.$mon.$year";
    }
    return $date;
}

sub get_args() {
# get %args from CLI or CGI arguments
    my (%args, $cgi_data);
    if ($#ARGV >= 0) {
        if ($#ARGV == 0) {
            $args{'act'} = $ARGV[0];
        } elsif ($#ARGV == 1) {
            $args{'old'} = $ARGV[0];
            $args{'new'} = $ARGV[1];
        }
        $args{'exec_method'} = 'CLI';
    } elsif (($ENV{'REQUEST_METHOD'} eq "GET") ||
            ($ENV{'REQUEST_METHOD'} eq "POST")) {
        ($cgi_data, %args) = get_cgi();
        $args{'exec_method'} = 'CGI';
    }
    return %args;
}

sub get_cgi() {
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

sub get_act($) {
# get act text from source file or URI given in argument
# if argument begins with https?://, use wget, otherwise file

    # get source file name or URI from argument, then attempt to read it
    local $_ = my $src = shift;
    my $act;
    if (m#^https?://#) {
        # input looks like URI
        $_ = uri_unescape($_);
        s#&amp;#&#g;
        
        # remove paragraph references from backlinks to superior acts in eRT
        if (/!/) {
            s#(.*?id=$NUMA)!.*?((?:&.*)|$)#$1$2#;
        }
        
        if (m#id=$NUMA;$NUMA#) { # multiple links given, display manual find
            print wrap(find_page($_));
            exit;
        }
        # redirect to "akt vorminduseta"
        s#https://www\.riigiteataja\.ee/ert/act\.jsp\?#https://www.riigiteataja.ee/ert/ert.jsp?link=print&akt_vorminduseta=1&#;
        $act = wget($_);
    } else {
        # input must be a file
        $act = `cat $_` || err("Cannot read '$_'.");
    }

    # extract <body> content
    $act =~ s#^.*?<body.*?>(.*?)</body>.*$#$1#s;

    # add link to source and formatter notice if source was URI
    if (m#^https?://#) {
        $act = qq#<p>wLex:src=$src</p>
$act#;
    }

    return $act;
}

sub find_act($;$) {
# get act text from URI or ((abbreviation or title) and date) given in argument
    local $_;
    my $act = shift;
    if ($act =~ m#^https?://#) {
        # act looks like URI, don't go searching
        $_ = get_act($act);
    } else {
        eval `cat abbr.pl`; # no warning given if abbr.pl is not found
        if ($_ = $Acts{$act}) { # try finding act name from abbreviation
            $act = $_;
        }
        my $now = shift;
        $now =~ m#^($NUMA)\.($NUMA)\.($NUMA)$#;
        my $search_ERT;
        if ($Args{'src'} eq "pealkirjadest") {
            $search_ERT =
              "/ert/ert.jsp?link=searchRes&date_day=$1&date_month=$2&date_year=$3&title=$act";
        } else {
            $search_ERT =
              "/ert/ert.jsp?link=searchRes&date_day=$1&date_month=$2&date_year=$3&text=$act";
        }
        my $search_this = $search_ERT;
        my $page_number = 0;
        while ($search_this) {
            # get next page of search results from eRT
            $_ = wget("https://www.riigiteataja.ee$search_this");
            # the xget/xact hack is necessary to make accented characters
            # case-insensitive in the following title search
            my $xget = $_; $xget =~ tr/ÕÄÖÜŠŽ/õäöüšž/;
            my $xact = $act; $xact =~ tr/ÕÄÖÜŠŽ/õäöüšž/;
            if ($xget =~ m#.*?<a href="(/ert/act.jsp\?id=[^"]*)">(?:<font [^>]*>)?(?:<u>)?$xact(?:¹)?(?:</u>)?(?:</font>)?</a>#si) {
                # exact title found
                $_ = get_act("https://www.riigiteataja.ee$1");
                $search_this = '';
            } elsif (($page_number++ < 3) &&
                    (m#<a href="($search_ERT&numberLink=$NUMA)"><img src="gfx/dot3.gif" .*?></a>#si)) {
                # any more pages available? (search up to 3 pages only)
                $search_this = $1;
            } else { # no more results available, exact title not found, revert to manual search
                print wrap(find_page("https://www.riigiteataja.ee$search_ERT"));
                exit;
            }
        }
    }
    return $_;
}

sub find_page($) {
# prepare to display manual search page
    local $_ = shift;
    $_ = wget($_);
    my $now = now;
    my $link;
    my $toc = qq#<div class="tocexploder">\n<div id="toc">\n#;
    s#.*?Soovitud aktid.*?<font .*?>(.*?)</font>##;
    $toc .= qq#<p class="ttl">Leitud aktid: $1</p>\n#;
    $toc .= qq#<p class="osa">#;
    # first page
    s#.*?(?:<a href="([^"]*?)">)?<img src="gfx/dot1.gif" .*?>##;
    if ($link = $1) {
        $link = "$wlexURI?find=https://www.riigiteataja.ee" .
                uri_escape($link) . "&act=$Args{'act'}&now=$now&src=$Args{'src'}";
        $toc .= qq#<a href="$link">&lt;&lt;</a> #;
    } else {
        $toc .= "&lt;&lt; ";
    }
    # previous page
    s#.*?(?:<a href="([^"]*)">)?<img src="gfx/dot2.gif" .*?>##;
    if ($link = $1) {
        $link = "$wlexURI?find=https://www.riigiteataja.ee" .
                uri_escape($link) . "&act=$Args{'act'}&now=$now&src=$Args{'src'}";
        $toc .= qq#<a href="$link">&lt;</a> #;
    } else {
        $toc .= "&lt; ";
    }
    # next page
    s#.*?(?:<a href="([^"]*)">)?<img src="gfx/dot3.gif" .*?>##;
    if ($link = $1) {
        $link = "$wlexURI?find=https://www.riigiteataja.ee" .
                uri_escape($link) . "&act=$Args{'act'}&now=$now&src=$Args{'src'}";
        $toc .= qq#<a href="$link">&gt;</a> #;
    } else {
        $toc .= "&gt; ";
    }
    # last page
    s#.*?(?:<a href="([^"]*)">)?<img src="gfx/dot4.gif" .*?>##;
    if ($link = $1) {
        $link = "$wlexURI?find=https://www.riigiteataja.ee" .
                uri_escape($link) . "&act=$Args{'act'}&now=$now&src=$Args{'src'}";
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
        $link = "$wlexURI?act=https://www.riigiteataja.ee" .
                uri_escape($1) . "&now=$now&src=$Args{'src'}";
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
    s#<img.*?<br />\n<br />##si;    # kill top-of-page spacer
    s#\s +# #gsi;                   # multiple spaces to single space
    s# ($NSUP)#$1#gsi;              # remove spaces before superscripted numbers
    s#§ -#§-#gsi;                   # remove spaces between § and -
    s#</?(?:b|i|strong|em)>##gsi;   # kill <b>, <i>, <strong>, <em> and their closing tags
    s#<p.*?>#<p>#gsi;               # fancy <p> to plain <p>
    s#<p> *$BR* *</p>##gsi;         # kill empty <p>...</p>
    ##### s#$BR\n?#</p>\n<p>#gsi;   # convert <br /> to </p>\n<p>
    s#\n *\n#\n#gsi;                # kill empty lines
    s#($NUMA)\. +($NUMA)#$1.$2#gs;  # spaced dates to nonspaced dates
    s#</?span.*?>##gsi;             # kill <span> and </span>
    s#</?div.*?>##gsi;              # kill <div> and </div>
    
    # complete eRT links or convert to wLex links
    if ($wlexURI) {
        s#<a href="(act.jsp[^"]*)#qq[<a href="$wlexURI?act=https://www.riigiteataja.ee/ert/].uri_escape($1)#gsei;
    } else {
        s#<a href="act.jsp#<a href="https://www.riigiteataja.ee/ert/act.jsp#gsi;
    }
    s#<img src="get-attachment.jsp#<img src="https://www.riigiteataja.ee/ert/get-attachment.jsp#gs;

    # get act title
    s#\n<p>(.*?)( ?$NSUP?)</p>#\n<p class="ttl">$1$2</p>#i;
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
        my $now = now;
        my $ttl = uri_escape($title);
        s#(<p class="x">.*?\) )($NUMA\.$NUMA\.$NUMA)(.*?)(</p>)#$1$2$3 (<a href="$wlexURI?act=$ttl&amp;&now=$2">vaata</a> | <a href="$wlexURI?act=$ttl&amp;new=$now&amp;old=$2">võrdle</a>)$4#gs;
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
# add wlex pagewrapper & searchbar to the body content provided,
# using optional page title
    local $_ = shift;
    my $title;
    if (m#<p class="ttl">(.*?) ?$NSUP?</p>#s) {
        $title = $1;
    }
    my $act = $Args{'act'};
    $act =~ s#https?://.*#$title#;
    $title = "wLex: $title" unless ($title =~ /^wLex: /);
    my $now = now();
    my $args = '';
    while (my ($key, $val) = each(%Args)) {
        $args .= "&$key=$val";
    }
    s#<div id="act">#<div id="act">
<div id="nav">
<form action="$wlexURI" method="get">
  <a href="$wlexURI">wLex</a> |
  otsing : <input type="text" name="act" size="30" value="$act" />
  seisuga <input type="text" name="now" size="10" value="$now" />
  <input type="submit" name="src" value="pealkirjadest" /
  <input type="submit" name="src" value="tekstidest" />
</form>
</div> <!-- /nav -->#;
    unless ($Args{'bug'}) {
        s#</form>#  | <a href="$wlexURI?bug=form$args">abi</a>
</form>#;
    }
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
    $cmd =~ s/(\s)(-)(\s|\z)/$1$tmp$3/ || err("No '-' in command '$cmd'.");
    $data = shift; # get data
    open TMP, ">$tmp" || err("Cannot open temporary file '$tmp' for writing.");
    print TMP $data;
    close TMP;
    local $_ = `$cmd`;
    unlink $tmp;
    return $_;
}

sub add_toc($) {
    # create table of contents
    # usage: add_toc($parsedAct)
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
            if ($tagA eq "<del>") {
                # special anchors for deleted paragraphs (in diff)
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

sub front_page() {
# show systematic catalog in TOC and welcome message in TXT
    my $toc = sys_cat();
    my $txt = qq#<div class="txtexploder">\n<div id="txt">\n# . `cat cover.htm` .
              qq#</div> <!-- /txt -->\n</div> <!-- /txtexploder -->#;
    $txt =~ s#\$progID#$progID#gs;
    return qq#<div id="act">\n$txt$toc</div> <!-- /act -->#;
}

sub sys_cat() {
# get systematic catalog from eRT
    my $toc = qq#<div class="tocexploder">\n<div id="toc">\n#;
    local $_ = wget('https://www.riigiteataja.ee/ert/ert.jsp?link=jaotusyksused-form');
    unless (m#<!-- Versioon $eRTver,.*?-->#) {
        # if expected version string is not found, display a warning
        $toc .= qq#<p class="pg" style="color: red"><strong>HOIATUS: eRT versioon on muutunud</strong></p>\n#;
    }
    while (s#.*?<td class="jaotusyksus".[^>]*>(.*?)</td>##is) {
        # build systematic catalog
        my $line = $1;
        if ($line =~ m#<b>(.*?)</b>#) { # section title
            $toc .= qq#<p class="osa">$1</p>\n#;
        } elsif ($line =~ m#<a href="(ert.jsp\?link=jaotusyksuse-aktid&jaotusyksused=.*?)">(.*?) *</a>#) {
            # subsection link
            my $link = $1;
            my $title = $2;
            $link = qq#$wlexURI?cat=https://www.riigiteataja.ee/ert/# .
                    uri_escape($link);
            $toc .= qq#<p class="pg"><a href="$link">$title</a></p>\n#;
        }
    }
    $toc .= qq#</div> <!-- /toc -->\n</div> <!-- /tocexploder -->#;
    return $toc;
}

sub cat_section($) {
# show selected section of eRT's systematic catalog
    my $toc = sys_cat();
    my $txt = qq#<div class="txtexploder">\n<div id="txt">\n<ul>\n#;
    local $_ = wget(shift);
    s#.*?class="pealkiri1"><b>(.*?) > (.*?) : ##;
    $txt .= qq#<p class="ttl">$1 &gt; $2</p>\n#;
    # list of acts
    while (s#.*?<a href="(/ert/act.jsp\?id=[^"]*)">(?:<font [^>]*>)?(?:<u>)?(.*?(?:¹)?)(?:</u>)?(?:</font>)?</a>##si) {
        my $link = "$wlexURI?act=https://www.riigiteataja.ee" . uri_escape($1);
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
    open TMP, ">$tfa" || err("Cannot open temporary file '$tfa' for writing.");
    print TMP $old;
    close TMP;
    while (1) {
        $tfb = rand;
        $tfb =~ s/0./$wlexTMP/; # ! uses global $TMP
        last unless (-e $tfb); # try again if file exists;
    }
    open TMP, ">$tfb" || err("Cannot open temporary file '$tfb' for writing.");
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

sub bug_form() {
# show bug report form
    my ($uri, $key, $val, $txt, $toc);
    
    # create TOC and prepare $uri
    my $toc = qq#<div class="tocexploder">\n<div id="toc">\n# .
              qq#<p class="pg"><b>$progID</b></p>
<p class="pg">issues: <a href="http://wolli.lighthouseapp.com/projects/28256/home">Lighthouse</a></p>
<p class="pg">source: <a href="http://github.com/wolli/wlex/">GitHub</a></p>
#;

    while (($key, $val) = each(%Args)) {
        unless (($key eq 'bug') || ($key eq 'exec_method')) {
            $toc .= qq#<p class="pg">$key: $val</p>\n#;
            if ($uri) {
                $uri .= "&$key=$val";
            } else {
                $uri = "$wlexURI?$key=$val";
            }
        }
    }
    $toc .= qq#</div> <!-- /toc -->\n</div> <!-- /tocexploder -->#;
    
    # create TXT
    $txt = qq#<div class="txtexploder">\n<div id="txt">\n# .
           qq#<p class="ttl">wLex: abiinfo</p>

<p>Kus viga näed laita, seal tule ja aita -- kasvõi viga laita ;)</p>

<p>Vigadest ja puudustest teatamine võimaldab mul wLex'i paremaks teha,
nii et pane aga julgesti kirja, mis Sind viimati vaadatud lehel häiris -- oli
see siis sisukorrastajale märkamata jäänud pealkiri või midagi muud.
Viide lehele, kus Sa probleemi märkasid, läheb teatega automaatselt kaasa,
nii et seda pole Sul vaja ümber kirjutama hakata. Lehe väljanägemist puudutavate
murede puhul tasuks aga kindlasti ära märkida, millist veebilehitsejat (Firefox,
Internet Explorer, Safari, Opera, ...) ja operatsioonisüsteemi Sa kasutad.
Kui oled huvitatud tagasisidest, lisa veateate lõppu oma nimi ja meiliaadress.</p>

<form action="$wlexURI" method="post"><p>
  <input type="hidden" name="bug" value="send" />
  <input type="hidden" name="URI" value="$uri" />
  <textarea name="report" rows="12" cols="60"></textarea>
  <br />
  <input type="submit" value="saada" />
</p></form>

<p>Tehnilist laadi lisainfo, mis mõeldud peamiselt asjatundlikele kasutajatele
ja võimalikele kaasautoritele, on kättesaadav <a href="http://wolli.lighthouseapp.com/projects/28256/home">siit</a>.</p>

<p>Kui sattusid siia kogemata ja teadet saata ei taha, mine
<a href="$uri">vaadatud akti juurde tagasi</a>.</p>
#;
    $txt .= qq#</div> <!-- /txt -->\n</div> <!-- /txtexploder -->#;

    return qq#<div id="act">\n$txt$toc</div> <!-- /act -->#;
}

sub bug_send() {
# send bug report, show message in TXT
    my ($txt, $key, $val, $toc, %mail);
    
    $toc = qq#<div class="tocexploder">\n<div id="toc">\n# .
           qq#</div> <!-- /toc -->\n</div> <!-- /tocexploder -->#;
    $txt = qq#<div class="txtexploder">\n<div id="txt">\n#;
    
    %mail = ( To      => $bugTO,
              From    => $bugFROM,
              Subject => $Args{'URI'},
              Message => $Args{'report'}
           );

    if (sendmail(%mail)) {
        # mail sent OK
        $txt .= qq#<p class="ttl">Tänan teavitamast!</p>

<pre>$Args{'report'}</pre>

<p>Sinu teade sai ära saadetud ning peaks olema edaspidi kättesaadav
<a href="$bugURI">siit</a>.</p>
<p>Kui panid kirja ka oma nime ja meiliaadressi, võtan Sinuga vajaduse ja võimaluse
tekkides ühendust.</p>

<p><a href="$Args{'URI'}">Tagasi akti juurde</a></p>
#;
    } else {
        # mail failure
        $txt .= qq#<p class="ttl">Tõrge teate saatmisel</p>
<pre>$Args{'report'}</pre>

<p>Sinu teate saatmine ebaõnnestus. Proovi see uuesti sisestada
<a href="$bugURI">siitkaudu</a>.</p>

<p><a href="$Args{'URI'}">Tagasi akti juurde</a></p>
#;
    }

    $txt .= qq#</div> <!-- /txt -->\n</div> <!-- /txtexploder -->#;
    
    return qq#<div id="act">\n$txt$toc</div> <!-- /act -->#;
}
