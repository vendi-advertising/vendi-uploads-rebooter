# Vendi Uploads Rebooter

Clean the uploads folder of everything except what you are actually using.

## Usage
To use this plugin:

1. Install and activate this plugin. It won't do anything until the next steps are completed.
1. Create a folder inside of your `uploads` folder called `__vendi_uploads_rebooter__`
1. Move all of your year folders into the folder above.
1. Either sit and wait or run a spider like [Xenu](http://home.snafu.de/tilman/xenulink.html) across your site. Also try and visit every page in the admin.

## Monitor
If you want you can SSH into your site, `cd` into the `__vendi_uploads_rebooter__` folder and run the command below. It will run the `find` command every second and count how many files are still in the backup folder.
<pre>watch -n1 'find . -type f | wc -l'</pre>