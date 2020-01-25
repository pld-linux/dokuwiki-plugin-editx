%define		plugin		editx
%define		php_min_version 5.0.0
Summary:	DokuWiki editx plugin
Name:		dokuwiki-plugin-%{plugin}
Version:	20140919
Release:	1
License:	GPL v2
Group:		Applications/WWW
Source0:	https://github.com/danny0838/dw-editx/tarball/master/%{plugin}-%{version}.tar.gz
# Source0-md5:	ea5edbf984caf7ed901979bb8051a94b
URL:		https://www.dokuwiki.org/plugin:editx
BuildRequires:	rpm-php-pearprov >= 4.4.2-11
BuildRequires:	rpmbuild(macros) >= 1.553
BuildRequires:	unzip
Requires:	dokuwiki >= 20061106
Requires:	php(core) >= %{php_min_version}
Requires:	php(date)
Requires:	php(pcre)
BuildArch:	noarch
BuildRoot:	%{tmpdir}/%{name}-%{version}-root-%(id -u -n)

%define		dokuconf	/etc/webapps/dokuwiki
%define		dokudir		/usr/share/dokuwiki
%define		plugindir	%{dokudir}/lib/plugins/%{plugin}
%define		find_lang 	%{_usrlibrpm}/dokuwiki-find-lang.sh %{buildroot}

%description
Extended edit functions such as renaming or deleting a page.

%prep
%setup -qc
mv *%{plugin}*/* .
%undos -f php

version=$(awk '/^date/{print $2}' plugin.info.txt)
if [ "$(echo "$version" | tr -d -)" != %{version} ]; then
	: %%{version} mismatch
	exit 1
fi

%install
rm -rf $RPM_BUILD_ROOT
install -d $RPM_BUILD_ROOT%{plugindir}
cp -a . $RPM_BUILD_ROOT%{plugindir}

%find_lang %{name}.lang

%clean
rm -rf $RPM_BUILD_ROOT

# use this post section if you package .css or .js files
%post
# force js/css cache refresh
if [ -f %{dokuconf}/local.php ]; then
	touch %{dokuconf}/local.php
fi

%files -f %{name}.lang
%defattr(644,root,root,755)
%dir %{plugindir}
%{plugindir}/*.php
%{plugindir}/*.txt
%{plugindir}/conf
