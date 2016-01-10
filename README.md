# MiniGS
A tiny grooveshark-style music site for personal use. You need to load your own music.

![screenshot](http://i.imgur.com/Eny6fI1.png)

# Features
- Basic search
- Automatic file reencoding
- Read tracks in cue sheets
- It's tiny - currently less than 600 lines of code and zero graphics

# Requirements
- LAMP server
- mediainfo
- ffmpeg with mp3,ogg,opus. (If you install from a repo it may not have mp3 and opus, and those formats won't work.)

# Setup
- Install requirements. (checkout https://trac.ffmpeg.org/wiki/CompilationGuide)
- Add the following lines to /etc/mysql/my.cnf . Otherwise search won't work right.
```
ft_min_word_len=2
ft_stopword_file=''
```
- Put the mysql username and password in settings.php .
- Create a new database called 'minigrooveshark' . (try phpMyAdmin if you don't like commandline) If you choose a different name you will need to edit the DB_NAME in settings.php.
- Load minigrooveshark.sql to create the table.
- Put your music folder in settings.php. Make sure to put a slash at the end of the folder name. If your music is scattered everywhere, create a folder and symlink all your music folders to it.
- run reloadtracks.php (Open it in your browser. It will take a while)
- Open index.html in your browser (through your server, don't open file locally) and try searching.

If you added or removed music files, run reloadtracks.php again to update the database. At this moment, reloadtracks.php doesn't deal with metadata-only changes. The only way to get track metadata in the database to update is to clear the table (TRUNCATE) and re-run reloadtracks.php.
