cefloom
=======

The Chromium Embedded Framework (CEF) in PHP: PHP opens the windows, loads the pages, answers their events and
is called from their JavaScript.

* The CEF API is generated from the CEF headers; its descriptions are the comments of those headers.
* PHP runs on one thread, the CEF UI thread, so a handler is plain PHP code.
* The guide explains how to use it; the reference is generated from ``php/cefloom.stub.php``.

.. toctree::
   :maxdepth: 2
   :caption: Guide

   installation
   getting-started
   cookbook
   concepts
   message-loop
   handlers
   javascript-bridge
   views
   context-menu
   media
   printing
   cast
   configuration
   wayland-gpu
   troubleshooting
   limitations
   development

.. toctree::
   :maxdepth: 2
   :caption: Reference

   api/index
