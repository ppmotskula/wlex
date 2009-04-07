# Copyright (C) 2009 Peeter P. Mõtsküla <peeterpaul@motskula.net>
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

# usage:
#   run `perl sitemap.pl` from the command line within your site directory
#   when you first set up your wLex instance and run it again whenever you
#   update (or get an updated version of) abbr.pl
#
# warning:
#   if you try to access this via web browser, you'll get a 500 error
#   because its output is not web-safe.
#
# dependencies:
#   cat (==> won't work on Windows)

use strict;
use POSIX;
use URI::Escape;

### global "constants"
my $progID  = 'wLex 3.1.2'; # will produce warning if versions do not match

### default config parameters and global variables
my $wlexURI = '';           # must be defined in config.pl
my %Acts;                   # must be provided in abbr.pl

### unused config parameters
my ($wlexTracker, $wlexTMP); # unused config.pl variables

main();

sub main() {
    # read and verify input files or die trying
    eval `cat abbr.pl` or die "Missing or invalid abbr.pl, exiting.\n";
    eval `cat config.pl` or die "Missing or invalid config.pl, exiting.\n";
    unless ($wlexURI) { die "\$wlexURI not defined in config.pl, exiting.\n" }
    $_ = `cat wlex.pl` or die "Cannot read wlex.pl, exiting.\n";
    unless (/my +\$progID += +'$progID';/) { die "Incorrect version of wlex.pl, exiting.\n" }

    # create sitemap.xml or die trying
    open SITEMAP, '>sitemap.xml' or die "Cannot write sitemap.xml, exiting.\n";
    print SITEMAP create_sitemap();
    close SITEMAP;
    print qq#Done. Please make sure your /robots.txt contains\n# .
          qq#'Sitemap: $wlexURI# . qq#sitemap.xml'.\n#;
}

sub create_sitemap() {
    my $sitemap = qq#<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>$wlexURI</loc>
    <priority>0.9</priority>
  </url>
#;
    while (my ($abbr, $title) = each %Acts) {
        # iterate over %Acts
        $abbr = uri_escape($abbr);
        $title = uri_escape($title);
        $sitemap .= qq#  <url>
    <loc>$wlexURI$abbr</loc>
    <priority>0.6</priority>
  </url>
  <url>
    <loc>$wlexURI$title</loc>
    <priority>0.3</priority>
  </url>
#;
    }
    $sitemap .= qq#</urlset>\n#;

    return $sitemap;
}

