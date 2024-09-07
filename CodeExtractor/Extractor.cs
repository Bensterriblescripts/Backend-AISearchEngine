using System.Diagnostics.CodeAnalysis;
using System.Text;
using System.Text.RegularExpressions;

namespace CodeExtractor;

[SuppressMessage("ReSharper", "MemberCanBePrivate.Global")]

public static class Extractor {

    private static readonly String[] supportedExtensions = new String[1] { ".php" }; // Case sensitive
    private static readonly String[] ignoreDir = new String[3] { ".git", ".idea", ".vs" }; // Case sensitive
    private static readonly String[] ignoreFiles = new String[1] { ".gitignore" }; // Case sensitive

    public static void Main() {
        String result = Extract();
        Console.WriteLine(result);
    }

    private static String Extract() {

        /* These may be read in from another source */
        Boolean MultiRepo = false;
        String BaseFolder = @"C:\Repositories\Backend-AISearchEngine";
        /* */
        String? error = null;

        if (String.IsNullOrEmpty(BaseFolder)) {
            return "No base folder provided.";
        }

        Console.WriteLine($"Base folder: {BaseFolder}");

        // Create StringBuilder objects to save on thousands of memory allocations - Passing strings creates copies (even with ref)
        // TODO: Try profile with Span<T> or MutableString (Commented at the bottom of this class)

        // Retrieve repository subfolders
        if (MultiRepo) {
            try {
                String[] directories = Directory.GetDirectories(BaseFolder);
                foreach (String folder in directories) {
                    Console.WriteLine($"Entering Repository: {folder}");
                    error = PHP_RetrieveRepositoryFiles(folder, ref error);
                    return $"Error : {error}";
                }
            }
            catch (Exception e) {
                return
                    $"Exception while trying to retrieve subdirectories in the base folder: {BaseFolder}. These should be repositories.\nException: {e}";
            }
        }
        else {
            Console.WriteLine($"Entering Repository: {BaseFolder}");
            error = PHP_RetrieveRepositoryFiles(BaseFolder, ref error);
            if (error != null) {
                return $"Error : {error}";
            }
        }

        return "Operation Completed.";
    }

    private static String? PHP_RetrieveRepositoryFiles(String repository, ref String? error) {
        Regex re_Namespace = new(@"namespace\s+([A-Za-z_][\w.]*)\s*;", RegexOptions.Compiled | RegexOptions.IgnoreCase);
        Regex re_Class =
            new(
                @"(abstract\s+|final\s+)?(class|interface|trait)\s+([A-Za-z_]\w*)\s*(extends\s+[A-Za-z_]\w*\s*)?(implements\s+[A-Za-z_]\w*(\s*,\s*[A-Za-z_]\w*)*\s*)?(\{|$)",
                RegexOptions.Compiled | RegexOptions.IgnoreCase);
        Regex re_Function = new(@"function\s+(&?\s*)?([A-Za-z_]\w*)\s*\([^)]*\)\s*(\{|:)",
            RegexOptions.Compiled | RegexOptions.IgnoreCase);

        try {
            // Iterate through the folder
            foreach (String filePath in Directory.GetFiles(repository, "*", SearchOption.AllDirectories)) {

                String fileName = Path.GetFileName(filePath);
                String extension = Path.GetExtension(filePath);

                // Ignore
                if (ignoreDir.Any(dir => filePath.Contains(dir))) {
                    // Console.WriteLine($"Ignoring file due to directory: {filePath}");
                    continue;
                }

                if (!supportedExtensions.Contains(extension)) {
                    // Console.WriteLine($"Extension {extension} is not supported.");
                    continue;
                }

                if (ignoreFiles.Contains(fileName)) {
                    // Console.WriteLine($"File {fileName} is ignored.");
                    continue;
                }

                String shortPath = Path.GetRelativePath(repository, filePath);
                Console.WriteLine($"\nFile {fileName}\nPath: {filePath}\nRelative Path: {shortPath}");
                
                error = ScrapeCode(filePath, re_Namespace, re_Class, re_Function, ref error);
            }

            return null;
        }
        catch (Exception e) {
            return $"Exception while trying to retrieve all files in the repository: {e.Message}";
        }
    }

    private static String? ScrapeCode(String file, Regex re_Namespace, Regex re_Class, Regex re_Function, ref String? error) {
        
        /*
         * Function Dictionary:
         * [Key] = Function name
         * [0] = Code
         * [1] = Function startline
         * [2] = Function endline
         */

        Int16 lineNumber = 0;
        Boolean classCreated = false;
        Boolean classOpen = false;
        Boolean functionCreated = false;
        Boolean functionOpen = false;

        List<String>? namespaces = new();

        StringBuilder currentClass = new();
        Dictionary<StringBuilder, Int16> classBracketCount = new();
        Dictionary<StringBuilder, ClassInfo> classes = new();

        StringBuilder currentFunction = new();
        Dictionary<StringBuilder, Int16> functionBracketCount = new();
        Dictionary<StringBuilder, FunctionInfo> functions = new();


        StringBuilder openCode = new();

        Match matchNamespace;
        Match matchClass;
        Match matchFunction;

        foreach (String line in File.ReadAllLines(file)) {
            lineNumber++;

            matchNamespace = re_Namespace.Match(line);
            matchFunction = re_Function.Match(line);
            matchClass = re_Class.Match(line);

            // Namespace
            if (matchNamespace.Success) {
                namespaces.Add(matchNamespace.Groups[1].Value);
            }

            // Class
            else if (matchClass.Success) {
                currentClass.Clear().Append(matchClass.Groups[3].Value);
                classes[currentClass] = new ClassInfo(new StringBuilder(), new StringBuilder(), 0, 0);
                classes[currentClass].LineStart = lineNumber;
                classCreated = true;
            }

            // Function
            else if (matchFunction.Success) {
                currentFunction.Clear().Append(matchFunction.Groups[2].Value);
                functions[currentFunction] = new FunctionInfo(new StringBuilder(), 0, 0);
                functions[currentFunction].LineStart = lineNumber;
                functionCreated = true;
            }

            // Bracket Opened
            if (line.Contains('{')) {
                if (functionCreated) {
                    functionCreated = false;
                    functionOpen = true;
                    functionBracketCount[currentFunction] = 0;
                }
                else if (classCreated) {
                    classCreated = false;
                    classOpen = true;
                    classBracketCount[currentClass] = 0;
                }
                else if (functionOpen) {
                    functionBracketCount[currentFunction]++;
                    if (classOpen) {
                        classes[currentClass].Functions.Append(currentFunction);
                    }
                }
                else if (classOpen) {
                    classBracketCount[currentClass]++;
                }
            }

            // Bracket Closed
            if (line.Contains('}')) {
                if (functionOpen) {
                    if (functionBracketCount[currentFunction] == 0) {
                        functionOpen = false;
                        functions[currentFunction].LineEnd = lineNumber;
                    }
                    else {
                        functionBracketCount[currentFunction]--;
                    }
                }
                else if (classOpen) {
                    if (classBracketCount[currentClass] == 0) {
                        classOpen = false;
                        classes[currentClass].LineEnd = lineNumber;
                    }
                    else {
                        classBracketCount[currentClass]--;
                    }
                }
            }

            // Record Code
            if (functionOpen) {
                functions[currentFunction].Code.AppendLine($"{lineNumber}: {line}");
            }
            else if (classOpen) {
                classes[currentClass].Code.AppendLine($"{lineNumber}: {line}");
            }
            else {
                openCode.AppendLine($"{lineNumber}: {line}");
            }
        }

        // Testing
        Console.WriteLine($"    Classes: {classes.Count}");
        Console.WriteLine($"    Functions: {functions.Count}");

        error = Queries.RunQuery(namespaces, classes, functions, openCode, error);
        namespaces.Clear();
        classes.Clear();
        functions.Clear();
        openCode.Clear();

        return null;
    }

    public class FunctionInfo(StringBuilder content, Int16 startLine, Int16 endLine) {
        public StringBuilder Code { get; set; } = content;
        public Int16 LineStart { get; set; } = startLine;
        public Int16 LineEnd { get; set; } = endLine;
    }

    public class ClassInfo(StringBuilder content, StringBuilder functions, Int16 startLine, Int16 endLine) {
        public StringBuilder Code { get; set; } = content;
        public StringBuilder Functions { get; set; } = functions;
        public Int16 LineStart { get; set; } = startLine;
        public Int16 LineEnd { get; set; } = endLine;
    }
}