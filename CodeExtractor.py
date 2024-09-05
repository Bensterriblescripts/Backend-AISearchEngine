import os
import re

# Base folder with repository folders inside
base_path = "C:\\Repositories\\Backend-AISearchEngine"
print(f"Base path set to: {base_path}")

# Ignore folders
ignoreDir = [".git", ".idea", ".vs"]

# Ignore files
ignoreFiles = [".gitignore"]

# General Regex - I know this is redundant, it's incase it needs additions
php_start = re.compile('<?php')
open_brace = re.compile('{')
close_brace = re.compile('}')



# # File Extraction

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

# Iterate through the lines
def extractCodeByLine(codeFile, re_namespace, re_class, re_function):

    open_doc = False
    created_class = False
    open_class = False
    created_function = False
    open_function = False
    created_sub_function = False
    open_sub_function = False

    sub_function_brace = 0
    function_brace = 0
    class_brace = 0

    # Please figure out a better way of doing this later...
    print(f"Entering file: {codeFile[1]}")
    with open(codeFile[1], 'r') as file:
        for line_number, line in enumerate(file, 1):

            # Non-object code
            if open_doc == False:
                match = re.search(php_start, line)
                if match:
                    open_doc = True
                else:
                    continue

            # Match namespace
            match = re.search(re_namespace, line)
            if match:
                codeNamespace[0] = match.group(1)
            
            # Match class
            match = re.search(re_class, line)
            if match:
                class_name = match.group(1)
                created_class = True
                count_classes[0] += 1
                class_brace = 0

            # Match function
            match = re.search(re_function, line)
            if match:
                if open_function:
                    fn_sub_function = match.group(1)
                    created_sub_function = True
                    sub_function_brace = 0
                else:
                    fn_function = match.group(1)
                    created_function = True
                    function_brace = 0
                count_functions[0] += 1

            # Match open brace
            match = re.search(open_brace, line)
            if match:

                # New function/class brace
                if created_class:
                    class_start = line_number
                    created_class = False
                    open_class = True
                    codeWithinClass[class_name] = ""
                elif created_function:
                    function_start = line_number
                    created_function = False
                    open_function = True
                    codeWithinFunction[fn_function] = ""
                elif created_sub_function:
                    sub_function_start = line_number
                    created_sub_function = False
                    open_sub_function = True
                    codeWithinFunction[fn_sub_function] = ""

                # Other brace
                elif open_sub_function:
                    sub_function_brace += 1
                elif open_function:
                    function_brace += 1
                elif open_class:
                    class_brace += 1

                # EOF
                elif open_doc:
                    open_doc = False

            # Content in a Function
            if open_sub_function:
                codeWithinFunction[fn_sub_function] += line
            elif open_function:
                codeWithinFunction[fn_function] += line
            elif open_class:
                codeWithinClass[class_name] += line
            elif open_doc:
                codeOpen[0] += line
            
            # Match close brace
            match = re.search(close_brace, line)
            if match:
                if open_sub_function:
                    sub_function_brace -= 1
                    if sub_function_brace == -1:
                        sub_function_end = line_number
                        open_sub_function = False
                        codeFunctions.append((f"{fn_sub_function}", codeNamespace, class_name, sub_function_start, sub_function_end))
                        codeWithinFunction[fn_sub_function] += " }"
                elif open_function:
                    function_brace -= 1
                    if function_brace == -1:
                        function_end = line_number
                        open_function = False
                        codeFunctions.append((fn_function, codeNamespace, class_name, function_start, function_end))
                        codeWithinFunction[fn_function] += " }"
                elif open_class:
                    class_brace -= 1
                    if class_brace == -1:
                        class_end = line_number
                        open_class = False
                        codeClasses.append((class_name, class_start, class_end))
                        codeWithinClass[class_name] += " }"


# Iterate through all files in the repoository
for repositoryFile in repositoryFiles:

    codeOpen = [""]
    codeNamespace = [""] # Strings must be a list to be mutable...
    codeClasses = []
    codeWithinClass = {}
    codeFunctions = []
    codeWithinFunction = {}

    count_classes = [0]
    count_functions = [0]

    print("")
    print(f"Repository: {repositoryFile[0]}")
    print(f"Path: {repositoryFile[2]}")
    print(f"File: {repositoryFile[3]}")

    # File type distinction
    name, extension = os.path.splitext(repositoryFile[3])
    if (extension == ".php"):
        print(f"Identified PHP script")

        # PHP Regex
        basic_namespace_php = re.compile('namespace\\s+(\\w+);')
        basic_class_php = re.compile('class\\s+(\\w+)\\s+{')
        basic_fn_php = re.compile('function\\s+(\\w+)')

        extractCodeByLine(repositoryFile, basic_namespace_php, basic_class_php, basic_fn_php)
        print(f"Total Functions: {count_functions[0]}")
        print(f"Total Classes: {count_classes[0]}")
        if (len(codeClasses) != count_classes[0]):
            print("Error: A class was missed.")
            continue
        if (len(codeFunctions) != count_functions[0]):
            print("Error: A function was missed.")
            continue

        print(f"\n    Namespace: {codeNamespace[0]}")
        # print(f"\n  Code outside: {codeOpen}")
        for classes in codeClasses:
            print(f"    Class: {classes[0]}\n")
            # print(f"    Class code: {codeWithinClass[classes[0]]}")
        for functions in codeFunctions:
            print(f"    Function: {functions[0]} | Start: {functions[3]}, End: {functions[4]}\n")
            # print(f"    Code:\n{codeWithinFunction[functions[0]]}")
        print("")


    # # Database Insertion

    # Locate repository
    addrepo = f'''
    MERGE (r:Repository {{name: {repositoryFile[0]}}}
    ON CREATE SET
        r.added = datetime()
    ON MATCH SET
        r.modified = datetime()
    '''
    # Add if it doesn't exist

    # Add all by function - repository -> document -> namespace -> class -> function