201~200~#!/usr/bin/env bash
set -e

echo "🧹 Initializing ProPhoto workspace git structure..."

ROOT_DIR="$(pwd)"

# -------------------------------------------------------------------
# Root git repo (workspace orchestration only)
# -------------------------------------------------------------------
if [ ! -d ".git" ]; then
  git init
    echo "✅ Initialized root git repo"
    else
      echo "ℹ️ Root git repo already exists"
      fi

      cat > .gitignore <<'EOF'
      # Workspace-level ignores
      /sandbox/
      .env
      .env.*
      .DS_Store

      # Logs
      *.log

      # OS
      Thumbs.db

      # Node
      **/node_modules/

      # PHP
      **/vendor/

      # Build artifacts
      **/dist/
      **/build/

      # Editor
      .idea/
      .vscode/

      # Temp
      .tmp/
      .cache/
      EOF

      git add .gitignore
      git commit -m "chore: initialize workspace gitignore" || true

      # -------------------------------------------------------------------
      # Initialize each prophoto-* package as its own repo
      # -------------------------------------------------------------------
      for dir in prophoto-*; do
        if [ -d "$dir" ]; then
            echo ""
                echo "📦 Processing $dir"

                    cd "$dir"

                        if [ ! -d ".git" ]; then
                              git init
                                    echo "  ✅ git init"
                                        else
                                              echo "  ℹ️ git already initialized"
                                                  fi

                                                      cat > .gitignore <<'EOF'
                                                      /vendor/
                                                      /node_modules/
                                                      .env
                                                      .env.*
                                                      .DS_Store
                                                      *.log
                                                      /dist/
                                                      /build/
                                                      EOF

                                                          git add .gitignore
                                                              git commit -m "chore: initialize package gitignore" || true

                                                                  cd "$ROOT_DIR"
                                                                    fi
                                                                    done

                                                                    echo ""
                                                                    echo "🎉 ProPhoto git initialization complete"
