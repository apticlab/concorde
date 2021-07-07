Concorde


**NB for Releasing**

Whene you use Concorde as a Laravel Plugin in production, is important to know that composer will load not the version that is present on master branch but the latest version based on tag, so if you have to push some changes for production launch the script `bumb-release.sh` that will create a new version.
