import os
import re

# Base folder with repository folders inside
base_path = "C:\\Repositories\\Backend-AISearchEngine"
print(f"Base path set to: {base_path}")

# Ignore folders
ignoreDir = [".git", ".idea", ".vs"]

# Ignore files
ignoreFiles = [".gitignore"]



# Locate each repository
repositoryFiles = []
repos = os.listdir(base_path)
for repo in repos:
    if ".git" in repo:
        continue

    # Repository name
    path = os.path.join(base_path, repo)
    # if os.path.isdir(path):
        # print(f"Repository: {repo}")

    # Iterate through the repository
    for root, dirs, files in os.walk(path):

        relative_path = os.path.relpath(root, path)

        # Ignore folders by name
        ignorefolder = False
        for ignore in ignoreDir:
            if ignore in relative_path:
                ignorefolder = True
                continue
        if ignorefolder: 
            continue

        # Retrieve the repositories
        for file in files:

            # Ignore files by name
            ignorefile = False
            for ignore in ignoreFiles:
                if ignore in file:
                    ignorefile = True
                    continue
            if ignorefile:
                continue
            
            # Store
            if relative_path == ".":
                repositoryFiles.append((repo, f"{path}\\{file}", "", file))
                continue

            repositoryFiles.append((repo, f"{path}\\{relative_path}\\{file}", f"{relative_path}\\", file))

# Find the functions by filetype
for repositoryFile in repositoryFiles:
    # print(f"Repository: {repositoryFile[0]}")
    # print(f"Path: {repositoryFile[1]}")
    # print(f"File: {repositoryFile[2]}")

    # File type distinction
    name, extension = os.path.splitext(repositoryFile[3])
    if (extension == ".php"):
        print(f"Located PHP script: {repositoryFile[2]}{repositoryFile[3]}")

        function = "function "

        print(f"Entering file: {repositoryFile[1]}")
        with open(repositoryFile[1], 'r') as file:
            for line_number, line in enumerate(file, 1):
                if re.search(function, line):
                    print(f"Line Number: {line_number}, Line: {line.strip()}")

        

    