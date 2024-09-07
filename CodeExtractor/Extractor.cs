using System.Diagnostics.CodeAnalysis;
using System.IO;
using System.Text.RegularExpressions;
using Neo4j.Driver;

namespace CodeExtractor;

[SuppressMessage("ReSharper", "MemberCanBePrivate.Global")]

public static class Extractor {
    private static readonly Regex re_PHPStart = new(@"<\?php", RegexOptions.Compiled | RegexOptions.IgnoreCase);
    private static readonly Regex re_OpenBrace = new(@"\{", RegexOptions.Compiled | RegexOptions.IgnoreCase);
    private static readonly Regex re_CloseBrace = new(@"<\}", RegexOptions.Compiled | RegexOptions.IgnoreCase);

    public static async Task Main() {
        String result = await Extract();
        Console.WriteLine(result);
    }

    private static async Task<String> Extract() {
        /* These may be read in from another source */
        Boolean MultiRepo = false;
        String BaseFolder = @"C:\Repositories\Backend-AISearchEngine";
        /* */
        String? error;
        String[] supportedExtensions = new String[1] { ".php" };
        String[] ignoreDir = new String[3] { ".git", ".idea", ".vs" };
        String[] ignoreFiles = new String[1] { ".gitignore" };
        Console.WriteLine($"Base folder: {BaseFolder}");
        
        // Neo4J Connection
        String? uri = Environment.GetEnvironmentVariable("NEO4J_URI");
        String? user = Environment.GetEnvironmentVariable("NEO4J_USER");
        String? pass = Environment.GetEnvironmentVariable("NEO4J_PASS");
        if (uri == null || user == null || pass == null) {
            error = "Unable to locate a Neo4j environment variable, please check they are configured. [NEO4J_URI, NEO4J_USER, NEO4J_PASS]";
            return error;
        }
        IDriver driver = GraphDatabase.Driver(uri, AuthTokens.Basic(user, pass));
        
        // Retrieve repository subfolders
        if (MultiRepo) {
            try {
                String[] directories = Directory.GetDirectories(BaseFolder);
                foreach (String folder in directories) {
                    Console.WriteLine($"Found Repository: {folder}");
                    (String[]? subFolders, error) = IterateRepository(folder);
                    if (subFolders == null && error != null) {
                        return error;
                    }
                    else if (subFolders == null) {
                        return "Uncaught error after retrieving subfolders.";
                    }
                }
            }
            catch (Exception e) {
                return $"Exception while trying to retrieve subdirectories in the base folder: {BaseFolder}. These should be repositories.\nException: {e}";
            }
        }
        else {
            Console.WriteLine($"Entering Repository: {BaseFolder}");
            (String[]? subFolders, error) = IterateRepository(BaseFolder);
            if (subFolders == null && error != null) {
                return error;
            }
            else if (subFolders == null) {
                return "Uncaught error after retrieving subfolders.";
            }
        }
        
        // Extract Code
        
        return "Operation Completed.";
    }
    private static (String[]?, String?) IterateRepository(String folder) {

        return (null, null);
    }
    
    private static async Task<string?> RunQuery(IDriver driver, String query) {
        
        try {
            IAsyncSession session = driver.AsyncSession();
            Console.WriteLine("Connected to Neo4j...");
            IResultCursor result = await session.RunAsync("MATCH (n) RETURN n");
            Console.WriteLine("Retrieved query result from Neo4j...");

            if (result == null) {
                return "Query failed or returned nothing.";
            }
            return result.ToString();

        }
        catch (Exception e) {
            return $"Exception while connecting to the Neo4j database: {e.Message}";
        }
    }
}