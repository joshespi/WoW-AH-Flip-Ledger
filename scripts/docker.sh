# Docker helpers for the scripts. Source from the repo root (after cd), don't execute.
# Git Bash on Windows needs a Windows-style path for docker -v and must not rewrite /repo.
HERE=$(pwd -W 2>/dev/null || pwd)
export MSYS_NO_PATHCONV=1

# alpine_run <script> [arg0 args...]: runs `sh -c <script>` in alpine with the repo at /repo.
alpine_run() {
    docker run --rm -v "$HERE:/repo" -w /repo alpine:3.20 sh -c "$@"
}
